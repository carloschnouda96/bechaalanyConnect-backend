<?php

namespace App\Http\Controllers\Cms;

use App\FixedSetting;
use App\Http\Controllers\Controller;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Repricing a batch of products in one pass.
 *
 * Before this, changing margin meant opening each product to edit its profit %, or each
 * variation to edit its price — one record at a time, with no way to see what the
 * catalog's margins currently were.
 *
 * All arithmetic goes through ProductsVariation::computeSellingPrice(), the same
 * function the supplier syncs use. There is no second copy of the markup formula here:
 * setting profit % on a supplier product writes only products.profit_percentage, and
 * App\Observers\ProductObserver pushes the recomputed prices down to the variations.
 */
class CatalogPricingController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $filters = $request->validate([
            'source' => ['nullable', 'string', 'max:40'],
            'subcategory_id' => ['nullable', 'integer'],
            'active' => ['nullable', Rule::in(['1', '0'])],
            'q' => ['nullable', 'string', 'max:120'],
            // Type a markup here to see what it would do before committing to it.
            'preview_profit' => ['nullable', 'numeric', 'between:-100,10000'],
        ]);

        $products = Product::withoutGlobalScope('cms_draft_flag')
            ->with(['translations', 'subcategory'])
            ->when(($filters['source'] ?? '') !== '', function ($query) use ($filters) {
                $filters['source'] === 'manual'
                    ? $query->whereNull('external_source')
                    : $query->where('external_source', $filters['source']);
            })
            ->when($filters['subcategory_id'] ?? null, fn ($q, $id) => $q->where('subcategory_id', $id))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', (int) $filters['active']))
            ->when($filters['q'] ?? null, function ($query, $term) {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $query->where(fn ($inner) => $inner
                    ->where('slug', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like)));
            })
            ->orderBy('external_source')
            ->orderBy('slug')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $default = (float) (FixedSetting::current()->default_profit_percentage ?? 0);
        $previewProfit = isset($filters['preview_profit']) && $filters['preview_profit'] !== null
            ? (float) $filters['preview_profit']
            : null;

        $rows = $products->getCollection()->map(function (Product $product) use ($default, $previewProfit) {
            $variations = $product->variations()
                ->withoutGlobalScope('cms_draft_flag')
                ->get(['id', 'price', 'cost_price', 'external_id']);

            $costs = $variations->pluck('cost_price')->filter(fn ($c) => $c !== null)->map(fn ($c) => (float) $c);
            $prices = $variations->pluck('price')->map(fn ($p) => (float) $p);

            $effective = $product->profit_percentage !== null
                ? (float) $product->profit_percentage
                : $default;

            // What the selected markup would produce, using the one shared formula.
            $projected = $previewProfit === null
                ? null
                : $costs->map(fn ($c) => ProductsVariation::computeSellingPrice($c, $previewProfit));

            return [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => optional($product->translate(app()->getLocale()))->name ?: $product->slug,
                'source' => $product->external_source,
                'is_active' => (int) $product->is_active,
                'profit' => $product->profit_percentage,
                'effective' => $effective,
                'variations' => $variations->count(),
                'cost_range' => $this->range($costs),
                'price_range' => $this->range($prices),
                'projected_range' => $projected && $projected->isNotEmpty() ? $this->range($projected) : null,
            ];
        });

        return view('cms::pages/catalog-pricing/index', [
            'products' => $products,
            'rows' => $rows,
            'filters' => $filters,
            'default_profit' => $default,
            'sources' => $this->sources(),
            'subcategories' => Subcategory::withoutGlobalScope('cms_draft_flag')->orderBy('slug')->get(['id', 'slug']),
        ]);
    }

    /**
     * Apply one pricing action to a selection.
     *
     * PUT, so AdminMiddleware maps it to the `edit` permission rather than `add`.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'string', 'max:10000'],
            'action' => ['required', Rule::in(['set_profit', 'adjust_price'])],
            'value' => ['required', 'numeric', 'between:-100,10000'],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(500)
            ->values();

        $value = (float) $data['value'];
        $changed = 0;
        $skipped = 0;

        DB::transaction(function () use ($ids, $data, $value, &$changed, &$skipped) {
            foreach ($ids as $id) {
                $product = Product::withoutGlobalScope('cms_draft_flag')->find($id);

                if (!$product) {
                    continue;
                }

                if ($data['action'] === 'set_profit') {
                    $changed += $this->setProfit($product, $value);
                    continue;
                }

                // adjust_price is a direct write to a variation's price, which is only
                // meaningful where a human owns that price. A supplier variation's price
                // is derived from cost x profit % and would be overwritten by the next
                // sync, so it is refused rather than silently reverted later.
                if (filled($product->external_source)) {
                    $skipped++;
                    continue;
                }

                $changed += $this->adjustPrices($product, $value);
            }
        });

        return back()->with('success', $this->summary($data['action'], $changed, $skipped));
    }

    /** @return int 1 if the product changed */
    private function setProfit(Product $product, float $percentage): int
    {
        if ($product->profit_percentage !== null
            && abs((float) $product->profit_percentage - $percentage) < 0.0001) {
            return 0;
        }

        $product->profit_percentage = $percentage;
        // ProductObserver::updated() repricess the supplier variations from cost.
        $product->save();

        // Manual products have no observer hook (deliberately — recalculateSupplierPrices
        // skips variations with no external_id so hand-set prices survive a markup edit).
        // Repricing them here is the explicit thing the admin asked for by selecting the
        // row, and only where a cost is actually recorded to compute from.
        if (blank($product->external_source)) {
            foreach ($product->variations()->withoutGlobalScope('cms_draft_flag')->get() as $variation) {
                if ($variation->cost_price === null) {
                    continue;
                }

                $variation->price = ProductsVariation::computeSellingPrice((float) $variation->cost_price, $percentage);
                $variation->save();
            }
        }

        return 1;
    }

    /** @return int number of variations repriced */
    private function adjustPrices(Product $product, float $percentage): int
    {
        $changed = 0;

        foreach ($product->variations()->withoutGlobalScope('cms_draft_flag')->get() as $variation) {
            $new = round((float) $variation->price * (1 + ($percentage / 100)), 2);

            if (abs((float) $variation->price - $new) < 0.0001) {
                continue;
            }

            $variation->price = $new;
            $variation->save();
            $changed++;
        }

        return $changed;
    }

    private function summary(string $action, int $changed, int $skipped): string
    {
        $what = $action === 'set_profit' ? 'product(s) repriced' : 'variation price(s) adjusted';
        $message = "{$changed} {$what}.";

        if ($skipped) {
            $message .= " {$skipped} supplier-owned product(s) skipped — set their profit % instead.";
        }

        return $message;
    }

    /** @return array<int, string> */
    private function sources(): array
    {
        $sources = Product::withoutGlobalScope('cms_draft_flag')
            ->whereNotNull('external_source')
            ->distinct()
            ->orderBy('external_source')
            ->pluck('external_source')
            ->all();

        return array_merge(['manual'], $sources);
    }

    /** @return array{0: float, 1: float}|null */
    private function range($values): ?array
    {
        if ($values->isEmpty()) {
            return null;
        }

        return [round((float) $values->min(), 4), round((float) $values->max(), 4)];
    }
}
