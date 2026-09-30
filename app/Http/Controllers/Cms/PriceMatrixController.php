<?php

namespace App\Http\Controllers\Cms;

use App\FixedSetting;
use App\Http\Controllers\Controller;
use App\Product;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\Subcategory;
use App\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Every variation's price for every audience on one screen.
 *
 * Rows are variations; columns are "Public" (guests and accounts with no user type —
 * products_variations.price) and one per user type (product_price_variations). Each
 * cell is either a fixed price or a profit % on the variation's cost.
 *
 * There is no pricing arithmetic here. A public % goes to products_variations.profit_percentage
 * and ProductsVariationObserver reprices it; a tier % goes to
 * product_price_variations.profit_percentage and ProductPriceVariationObserver resolves
 * it. Both end in ProductsVariation::computeSellingPrice(), the formula the supplier
 * syncs use, and both stored prices stay what OrderController::saveOrder charges.
 */
class PriceMatrixController extends Controller
{
    private const PER_PAGE = 50;

    public const PUBLIC = 'public';

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

        $userTypes = $this->userTypes();
        $default = (float) (FixedSetting::current()->default_profit_percentage ?? 0);
        $locale = app()->getLocale();

        $rows = $products->getCollection()->flatMap(function (Product $product) use ($userTypes, $default, $locale) {
            $productName = optional($product->translate($locale))->name ?: $product->slug;

            return $product->variations->map(function (ProductsVariation $variation) use ($product, $productName, $userTypes, $default, $locale) {
                $cost = $variation->cost_price ?? $variation->external_price;
                $cost = $cost === null ? null : (float) $cost;

                $cells = [self::PUBLIC => $this->publicCell($variation, $cost, $default)];

                foreach ($userTypes as $type) {
                    $cells[$type['id']] = $this->tierCell(
                        $variation->priceVariations->firstWhere('user_types_id', $type['id']),
                        $cost
                    );
                }

                return [
                    'id' => $variation->id,
                    'product_id' => $product->id,
                    'product' => $productName,
                    'name' => optional($variation->translate($locale))->name ?: $variation->slug,
                    'source' => $product->external_source,
                    'is_active' => (int) $variation->is_active && (int) $product->is_active,
                    'cost' => $cost,
                    'cells' => $cells,
                ];
            });
        });

        return view('cms::pages/price-matrix/index', [
            'products' => $products,
            'rows' => $rows,
            'user_types' => $userTypes,
            'filters' => $filters,
            'default_profit' => $default,
            'sources' => $this->sources(),
            'subcategories' => Subcategory::withoutGlobalScope('cms_draft_flag')->orderBy('slug')->get(['id', 'slug']),
        ]);
    }

    /**
     * Apply a set of cell changes — edited cells from the grid, or one bulk action
     * expanded over the selected variations. Both go through applyCell(), so there is
     * one write path.
     *
     * PUT, so AdminMiddleware maps it to the `edit` permission rather than `add`.
     */
    public function update(Request $request)
    {
        $typeIds = array_column($this->userTypes(), 'id');
        $columns = array_merge([self::PUBLIC], array_map('strval', $typeIds));

        $data = $request->validate([
            'changes' => ['nullable', 'string', 'max:200000'],
            'bulk_ids' => ['nullable', 'string', 'max:20000'],
            'bulk_column' => ['nullable', Rule::in($columns)],
            'bulk_mode' => ['nullable', Rule::in(['fixed', 'percent', 'clear', 'adjust'])],
            'bulk_value' => ['nullable', 'numeric'],
        ]);

        $changes = $this->changesFrom($data);

        if ($changes->isEmpty()) {
            return back()->withErrors(['changes' => 'Nothing to apply — edit a cell or select variations for a bulk action.']);
        }

        $errors = [];
        foreach ($changes as $change) {
            if (!in_array((string) $change['column'], $columns, true)) {
                $errors[] = 'Unknown column "' . $change['column'] . '".';
            }
            if ($change['mode'] === 'adjust' && $change['column'] !== self::PUBLIC) {
                $errors[] = 'Adjusting by % only applies to the Public price — set a user type\'s price as a fixed price or profit % instead.';
            }
            if (in_array($change['mode'], ['percent', 'adjust'], true) && !$this->between($change['value'], -100, 10000)) {
                $errors[] = 'Profit % must be between -100 and 10000.';
            }
            if ($change['mode'] === 'fixed' && !$this->between($change['value'], 0, 1000000)) {
                $errors[] = 'A fixed price must be a number of 0 or more.';
            }
        }

        if ($errors) {
            return back()->withErrors(array_values(array_unique($errors)));
        }

        $variations = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->whereIn('id', $changes->pluck('variation_id')->unique())
            ->get()
            ->keyBy('id');

        $changed = 0;
        $skipped = [];

        DB::transaction(function () use ($changes, $variations, &$changed, &$skipped) {
            foreach ($changes as $change) {
                $variation = $variations->get($change['variation_id']);

                if (!$variation) {
                    continue;
                }

                $result = $this->applyCell($variation, $change);

                if ($result === true) {
                    $changed++;
                } elseif (is_string($result)) {
                    $skipped[] = $result;
                }
            }
        });

        return back()
            ->with('success', "{$changed} price(s) updated." . ($skipped ? ' ' . count($skipped) . ' skipped.' : ''))
            ->with('price_matrix_skipped', $skipped);
    }

    /**
     * @return bool|string true when something changed, false when the cell already
     *     held that value, or the reason it was skipped
     */
    private function applyCell(ProductsVariation $variation, array $change)
    {
        $hasCost = ($variation->cost_price ?? $variation->external_price) !== null;
        $label = $variation->slug;

        if ($change['mode'] === 'percent' && !$hasCost) {
            return "{$label}: no cost price recorded, so a profit % cannot apply — set a fixed price instead.";
        }

        if ($change['mode'] === 'adjust') {
            return $this->adjustPublic($variation, $change['value']);
        }

        return $change['column'] === self::PUBLIC
            ? $this->applyPublic($variation, $change['mode'], $change['value'])
            : $this->applyTier($variation, (int) $change['column'], $change['mode'], $change['value']);
    }

    /**
     * The public price keeps its existing semantics exactly — this only writes the
     * fields an admin would write on the Products Variations page, and
     * ProductsVariationObserver does the rest.
     */
    private function applyPublic(ProductsVariation $variation, string $mode, ?float $value): bool
    {
        if ($mode === 'fixed') {
            if (abs((float) $variation->price - $value) < 0.0001 && $variation->manual_price) {
                return false;
            }
            $variation->price = $value;
            // Also when the price is unchanged: the admin just chose fixed mode, and the
            // lock is what tells the supplier sync to stop repricing it.
            $variation->manual_price = true;
            $variation->save();

            return true;
        }

        // percent, or clear (= inherit the Fixed Settings default)
        $variation->profit_percentage = $mode === 'clear' ? null : $value;

        if ($variation->isDirty('profit_percentage')) {
            $variation->save();

            return true;
        }

        // Same % as before but the price was locked by hand: hand it back to the formula.
        $price = $variation->priceFromCost();
        if ($price === null || (!$variation->manual_price && abs((float) $variation->price - $price) < 0.0001)) {
            return false;
        }

        ProductsVariation::applyingSystemPricing(function () use ($variation, $price) {
            $variation->price = $price;
            $variation->manual_price = false;
            $variation->save();
        });

        return true;
    }

    /**
     * Multiply the current public price (the old Bulk pricing "adjust prices by %").
     *
     * A supplier variation's price is derived from cost x profit % and the next sync
     * would revert a direct write, so it is refused here rather than silently undone
     * later. The observer locks the new price (manual_price), as for any hand edit.
     *
     * @return bool|string
     */
    private function adjustPublic(ProductsVariation $variation, float $percentage)
    {
        if (filled(optional($variation->product)->external_source)) {
            return "{$variation->slug}: supplier product — its price follows cost x profit %, so set a profit % instead of adjusting.";
        }

        $new = round((float) $variation->price * (1 + ($percentage / 100)), 2);

        if (abs((float) $variation->price - $new) < 0.0001) {
            return false;
        }

        $variation->price = $new;
        $variation->save();

        return true;
    }

    private function applyTier(ProductsVariation $variation, int $userTypeId, string $mode, ?float $value): bool
    {
        $row = ProductPriceVariation::withoutGlobalScope('cms_draft_flag')
            ->where('products_variations_id', $variation->id)
            ->where('user_types_id', $userTypeId)
            ->first();

        if ($mode === 'clear') {
            if (!$row) {
                return false;
            }
            $row->delete();

            return true;
        }

        $row = $row ?: new ProductPriceVariation([
            'products_variations_id' => $variation->id,
            'user_types_id' => $userTypeId,
        ]);
        $row->cms_draft_flag = 0;

        if ($mode === 'fixed') {
            $row->profit_percentage = null;
            $row->price = $value;
        } else {
            $row->profit_percentage = $value;
        }

        if ($row->exists && !$row->isDirty()) {
            return false;
        }

        $row->save();

        return true;
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

        $decoded = json_decode($data['changes'] ?? '[]', true);

        return collect(is_array($decoded) ? $decoded : [])
            ->filter(fn ($c) => is_array($c)
                && isset($c['variation_id'], $c['column'], $c['mode'])
                && in_array($c['mode'], ['fixed', 'percent', 'clear'], true)
                && ($c['mode'] === 'clear' || is_numeric($c['value'] ?? null)))
            ->take(2000)
            ->map(fn ($c) => [
                'variation_id' => (int) $c['variation_id'],
                'column' => (string) $c['column'],
                'mode' => $c['mode'],
                'value' => $c['mode'] === 'clear' ? null : (float) $c['value'],
            ])
            ->values();
    }

    /**
     * Public cell: % mode only when the stored price really is cost x (own % or the
     * default). Otherwise it is shown as fixed — a locked price, no cost to derive from,
     * or a hand-entered price on a manual product that never went through the formula
     * (those have manual_price = 0 but a price unrelated to their cost).
     */
    private function publicCell(ProductsVariation $variation, ?float $cost, float $default): array
    {
        $price = (float) $variation->price;
        $percentage = $variation->profit_percentage !== null ? (float) $variation->profit_percentage : $default;
        $fixed = $variation->manual_price
            || $cost === null
            || abs(ProductsVariation::computeSellingPrice($cost, $percentage) - $price) >= 0.005;

        return [
            'mode' => $fixed ? 'fixed' : 'percent',
            'value' => $fixed ? $price : ($variation->profit_percentage !== null ? (float) $variation->profit_percentage : null),
            'inherits_default' => !$fixed && $variation->profit_percentage === null,
            'default' => $default,
            'price' => $price,
            'below_cost' => $cost !== null && $price < $cost,
        ];
    }

    /** Tier cell: empty (pays the public price), fixed, or % on cost. */
    private function tierCell(?ProductPriceVariation $row, ?float $cost): array
    {
        if (!$row) {
            return ['mode' => 'clear', 'value' => null, 'price' => null, 'below_cost' => false];
        }

        $price = (float) $row->price;

        return [
            'mode' => $row->isPercentMode() ? 'percent' : 'fixed',
            'value' => $row->isPercentMode() ? (float) $row->profit_percentage : $price,
            'price' => $price,
            'below_cost' => $cost !== null && $price < $cost,
        ];
    }

    /** @return array<int, array{id: int, title: string}> */
    private function userTypes(): array
    {
        $locale = app()->getLocale();

        return UserType::with('translations')
            ->orderBy('ht_pos')
            ->orderBy('id')
            ->get()
            ->map(fn (UserType $type) => [
                'id' => $type->id,
                'title' => optional($type->translate($locale))->title ?: $type->slug,
            ])
            ->all();
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

    private function between($value, float $min, float $max): bool
    {
        return is_numeric($value) && $value >= $min && $value <= $max;
    }
}
