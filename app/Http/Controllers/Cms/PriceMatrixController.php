<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Product;
use App\ProductsVariation;
use App\Services\Cms\VariationPricing;
use App\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Every variation's price for every audience on one screen.
 *
 * Rows are variations; columns are "Public" (guests and accounts with no user type —
 * products_variations.price) and one per user type (product_price_variations). Each
 * cell is either a fixed price or a profit % on the variation's cost.
 *
 * All reading and writing of cells is VariationPricing's — the product editor's
 * Variations card uses the same service, so the two screens cannot drift apart.
 */
class PriceMatrixController extends Controller
{
    private const PER_PAGE = 50;

    public const PUBLIC = VariationPricing::PUBLIC;

    public function __construct(private VariationPricing $pricing)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'source' => ['nullable', 'string', 'max:40'],
            'subcategory_id' => ['nullable', 'integer'],
            'active' => ['nullable', Rule::in(['1', '0'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $products = Product::withoutGlobalScope('cms_draft_flag')
            ->with([
                'translations',
                'variations' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')
                    ->with(['translations', 'priceVariations'])
                    ->orderBy('ht_pos')
                    ->orderBy('id'),
            ])
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

        $locale = app()->getLocale();

        $rows = $products->getCollection()->flatMap(function (Product $product) use ($locale) {
            $productName = optional($product->translate($locale))->name ?: $product->slug;

            return $product->variations->map(fn (ProductsVariation $variation) => [
                'id' => $variation->id,
                'product_id' => $product->id,
                'product' => $productName,
                'name' => optional($variation->translate($locale))->name ?: $variation->slug,
                'source' => $product->external_source,
                'is_active' => (int) $variation->is_active && (int) $product->is_active,
                'cost' => VariationPricing::costOf($variation),
                'cells' => $this->pricing->cells($variation),
            ]);
        });

        return view('cms::pages/price-matrix/index', [
            'products' => $products,
            'rows' => $rows,
            'user_types' => $this->pricing->userTypes(),
            'filters' => $filters,
            'default_profit' => $this->pricing->defaultProfit(),
            'sources' => $this->sources(),
            'subcategories' => Subcategory::withoutGlobalScope('cms_draft_flag')->orderBy('slug')->get(['id', 'slug']),
        ]);
    }

    /**
     * Apply a set of cell changes — edited cells from the grid, or one bulk action
     * expanded over the selected variations. Both go through VariationPricing::apply(),
     * so there is one write path.
     *
     * PUT, so AdminMiddleware maps it to the `edit` permission rather than `add`.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'changes' => ['nullable', 'string', 'max:200000'],
            'bulk_ids' => ['nullable', 'string', 'max:20000'],
            'bulk_column' => ['nullable', Rule::in($this->pricing->columns())],
            'bulk_mode' => ['nullable', Rule::in(['fixed', 'percent', 'clear', 'adjust'])],
            'bulk_value' => ['nullable', 'numeric'],
        ]);

        $changes = $this->changesFrom($data);

        if ($changes->isEmpty()) {
            return back()->withErrors(['changes' => 'Nothing to apply — edit a cell or select variations for a bulk action.']);
        }

        if ($errors = $this->pricing->validate($changes)) {
            return back()->withErrors($errors);
        }

        $result = $this->pricing->apply($changes);

        return back()
            ->with('success', "{$result['changed']} price(s) updated." . ($result['skipped'] ? ' ' . count($result['skipped']) . ' skipped.' : ''))
            ->with('price_matrix_skipped', $result['skipped']);
    }

    /** @return Collection<int, array{variation_id: int, column: string, mode: string, value: float|null}> */
    private function changesFrom(array $data): Collection
    {
        if (filled($data['bulk_ids'] ?? null)) {
            $mode = $data['bulk_mode'] ?? null;
            $column = $data['bulk_column'] ?? null;
            $value = isset($data['bulk_value']) ? (float) $data['bulk_value'] : null;

            if (!$mode || !$column || ($mode !== 'clear' && $value === null)) {
                return collect();
            }

            return collect(explode(',', $data['bulk_ids']))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->unique()
                ->take(1000)
                ->map(fn ($id) => ['variation_id' => $id, 'column' => $column, 'mode' => $mode, 'value' => $value])
                ->values();
        }

        return VariationPricing::changesFromJson($data['changes'] ?? null);
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
}
