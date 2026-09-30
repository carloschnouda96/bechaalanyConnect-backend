<?php

namespace App\Services\Cms;

use App\FixedSetting;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\UserType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way the CMS reads and writes a variation's prices.
 *
 * A variation has a "Public" price (guests and accounts with no user type —
 * products_variations.price) and one optional price per user type
 * (product_price_variations). Each is a fixed price or a profit % on the variation's
 * cost. The Price matrix page, the product editor's Variations card and the variation
 * form all go through here, so the three screens cannot disagree about what a cell
 * means or how it is saved.
 *
 * There is no pricing arithmetic here. A public % goes to products_variations.profit_percentage
 * and ProductsVariationObserver reprices it; a tier % goes to
 * product_price_variations.profit_percentage and ProductPriceVariationObserver resolves
 * it. Both end in ProductsVariation::computeSellingPrice(), the formula the supplier
 * syncs use, and both stored prices stay what OrderController::saveOrder charges.
 */
class VariationPricing
{
    public const PUBLIC = 'public';

    /** @var array<int, array{id: int, title: string}>|null */
    private ?array $userTypes = null;

    /** @return array<int, array{id: int, title: string}> */
    public function userTypes(): array
    {
        if ($this->userTypes !== null) {
            return $this->userTypes;
        }

        $locale = app()->getLocale();

        return $this->userTypes = UserType::with('translations')
            ->orderBy('ht_pos')
            ->orderBy('id')
            ->get()
            ->map(fn (UserType $type) => [
                'id' => $type->id,
                'title' => optional($type->translate($locale))->title ?: $type->slug,
            ])
            ->all();
    }

    /** Every writable column key: 'public' plus each user type id as a string. */
    public function columns(): array
    {
        return array_merge([self::PUBLIC], array_map('strval', array_column($this->userTypes(), 'id')));
    }

    public function defaultProfit(): float
    {
        return (float) (FixedSetting::current()->default_profit_percentage ?? 0);
    }

    /** The recorded unit cost, or null for a hand-priced variation with none. */
    public static function costOf(ProductsVariation $variation): ?float
    {
        $cost = $variation->cost_price ?? $variation->external_price;

        return $cost === null ? null : (float) $cost;
    }

    /**
     * Every cell of one variation's row, keyed by column. The variation's
     * priceVariations relation should already be loaded.
     */
    public function cells(ProductsVariation $variation): array
    {
        $cost = self::costOf($variation);
        $cells = [self::PUBLIC => $this->publicCell($variation, $cost, $this->defaultProfit())];

        foreach ($this->userTypes() as $type) {
            $cells[$type['id']] = $this->tierCell(
                $variation->priceVariations->firstWhere('user_types_id', $type['id']),
                $cost
            );
        }

        return $cells;
    }

    /**
     * Public cell: % mode only when the stored price really is cost x (own % or the
     * default). Otherwise it is shown as fixed — a locked price, no cost to derive from,
     * or a hand-entered price on a manual product that never went through the formula
     * (those have manual_price = 0 but a price unrelated to their cost).
     */
    public function publicCell(ProductsVariation $variation, ?float $cost, float $default): array
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
    public function tierCell(?ProductPriceVariation $row, ?float $cost): array
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

    /**
     * Reasons a set of changes cannot be applied at all (unknown column, value out of
     * range). Per-variation refusals — a % with no cost, adjusting a supplier price —
     * are reported by apply() as "skipped" instead, so one bad row does not block the rest.
     *
     * @param Collection<int, array{variation_id: int, column: string, mode: string, value: float|null}> $changes
     * @return string[]
     */
    public function validate(Collection $changes): array
    {
        $columns = $this->columns();
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

        return array_values(array_unique($errors));
    }

    /**
     * Apply already-validated changes in one transaction.
     *
     * @param Collection<int, array{variation_id: int, column: string, mode: string, value: float|null}> $changes
     * @param int|null $productId when set, only variations of this product are touched
     *     (the product editor posts ids of its own rows; anything else is ignored)
     * @return array{changed: int, skipped: string[]}
     */
    public function apply(Collection $changes, ?int $productId = null): array
    {
        $variations = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->whereIn('id', $changes->pluck('variation_id')->unique())
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
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

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * @return bool|string true when something changed, false when the cell already
     *     held that value, or the reason it was skipped
     */
    public function applyCell(ProductsVariation $variation, array $change)
    {
        $label = $variation->slug;

        if ($change['mode'] === 'percent' && self::costOf($variation) === null) {
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
     *
     * @param string $mode fixed | percent | clear (= inherit the Fixed Settings default)
     */
    public function applyPublic(ProductsVariation $variation, string $mode, ?float $value): bool
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
    public function adjustPublic(ProductsVariation $variation, float $percentage)
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

    public function applyTier(ProductsVariation $variation, int $userTypeId, string $mode, ?float $value): bool
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

    /**
     * Parse the grid's `changes` JSON (the edited cells) into change rows.
     *
     * @return Collection<int, array{variation_id: int, column: string, mode: string, value: float|null}>
     */
    public static function changesFromJson(?string $json): Collection
    {
        $decoded = json_decode($json ?? '[]', true);

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

    private function between($value, float $min, float $max): bool
    {
        return is_numeric($value) && $value >= $min && $value <= $max;
    }
}
