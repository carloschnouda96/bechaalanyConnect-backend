<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class ProductsVariation extends Model  implements TranslatableContract
{
    use Translatable;

    protected $table = 'products_variations';

    protected $guarded = ['id'];

    protected $hidden = ['translations'];

    public $translatedAttributes = ["name", "description", "unit_label"];

    protected static function booted()
    {
        static::addGlobalScope('cms_draft_flag', function (Builder $builder) {
            $builder->where('products_variations.cms_draft_flag', '!=', 1);
        });
    }
    public function product()
    {
        return $this->belongsTo('App\Product');
    }

    /* Start custom functions */

    // Everything below the marker is preserved when the hellotree CMS regenerates
    // this file on a page-schema save; everything above it is rewritten from
    // src/stubs/model.stub. `use`, `$casts`, `$hidden`, hasMany relations and
    // static helpers must therefore live down here — see App\Concerns\HidesExtraAttributes.

    use \App\Concerns\HasFullPath;
    use \App\Concerns\HidesExtraAttributes;
    use \App\Concerns\AppendsToCmsOrder;

    /**
     * Merged into the regenerated `$hidden = ['translations']`.
     *
     * cost_price is the supplier's unit cost. Leaving it exposed published our
     * margin on the public storefront: ProductController::SingleProduct returns
     * variations unauthenticated, so anyone could derive the markup on every product.
     * profit_percentage is hidden for the same reason.
     * external_qty_values stays visible — the storefront renders it as preset amounts.
     * supplier_status mirrors Product::$extraHidden — an internal sync signal,
     * filtered on server-side via sellable(), never needed by the client.
     */
    protected $extraHidden = [
        'external_id',
        'external_source',
        'external_price',
        'external_type',
        'cost_price',
        'profit_percentage',
        'supplier_status',
    ];

    // NOTE: money casts (price => decimal:2) are deliberately NOT added here yet.
    // A decimal cast serialises to a JSON string ("12.50"), which the storefront
    // would feed straight into arithmetic. They land in the money migration,
    // together with the frontend number normaliser.
    protected $casts = [
        'unit_amount' => 'integer',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'external_qty_values' => 'array',
        // Sync-owned customer-facing service details (1xpanel), shipped to the
        // storefront as-is — no cost data in it, unlike its external_* siblings.
        'external_details' => 'array',
        'manual_price' => 'boolean',
        'profit_percentage' => 'decimal:2',
    ];

    /**
     * Guards SupplierCatalogSync::upsertVariation() / ProductsVariationObserver repricing
     * writes so ProductsVariationObserver doesn't mistake the sync's own price
     * update for an admin edit and lock the row. See that observer's docblock.
     */
    private static bool $applyingSystemPricing = false;

    public static function applyingSystemPricing(callable $callback)
    {
        $previous = self::$applyingSystemPricing;
        self::$applyingSystemPricing = true;
        try {
            return $callback();
        } finally {
            self::$applyingSystemPricing = $previous;
        }
    }

    public static function isApplyingSystemPricing(): bool
    {
        return self::$applyingSystemPricing;
    }

    /**
     * A variation the storefront may sell: this row is switched on AND (it
     * isn't supplier-managed OR the supplier currently has it in stock) AND
     * its parent product is sellable too (Product::scopeSellable — a product
     * can be admin-deactivated independently of its variations). See
     * Product::scopeSellable() for the shared ownership model.
     */
    public function scopeSellable($query)
    {
        return $query->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('supplier_status')
                    ->orWhere('supplier_status', Product::SUPPLIER_AVAILABLE);
            })
            ->whereHas('product', fn ($q) => $q->sellable());
    }

    /**
     * All price variations (one per user type typically).
     */
    public function priceVariations()
    {
        return $this->hasMany(ProductPriceVariation::class, 'products_variations_id');
    }

    /**
     * Single source of truth for the supplier markup formula:
     * selling price = cost * (1 + profit% / 100), rounded to 2 decimals.
     */
    public static function computeSellingPrice(float $cost, float $profitPercentage): float
    {
        return round($cost * (1 + ($profitPercentage / 100)), 2);
    }

    /**
     * The supplier that fulfils this variation, or null for a hand-made one.
     *
     * The variation's own column wins: a supplier category mapped to an admin-made
     * product (supplier_categories.product_id) puts supplier variations under a
     * product with no `external_source`. The parent is the fallback for rows the
     * 2026_10_09_000002 backfill did not reach.
     */
    public function supplierSource(): ?string
    {
        return $this->external_source ?: optional($this->product)->external_source;
    }

    /**
     * The markup % to apply to this variation's cost, falling back to the global
     * default in Fixed Settings when no per-variation value is set.
     */
    public function effectiveProfitPercentage(): float
    {
        if ($this->profit_percentage !== null) {
            return (float) $this->profit_percentage;
        }
        $default = FixedSetting::current()->default_profit_percentage;
        return (float) ($default ?? 0);
    }

    /**
     * The selling price this variation's cost and effective profit % produce, or
     * null when no cost is recorded (a hand-priced variation) — there is then
     * nothing to derive a price from, and the caller must leave `price` alone.
     *
     * `price` is the price of pricingUnit() units, so the per-unit cost is scaled
     * by it first. That is what lets a per-coin supplier cost ($0.000105 per
     * Jawaker coin) survive `price` being decimal(12,2): priced per 1,000 coins it
     * is $0.13, not $0.00.
     */
    public function priceFromCost(): ?float
    {
        $cost = $this->cost_price ?? $this->external_price;
        if ($cost === null) {
            return null;
        }
        return self::computeSellingPrice((float) $cost * $this->pricingUnit(), $this->effectiveProfitPercentage());
    }

    /**
     * How many units `price` buys: `unit_amount` (coins per price, e.g. 1,000)
     * or 1. Shared by every cost→price formula and by the coin order total.
     */
    public function pricingUnit(): int
    {
        return max(1, (int) ($this->unit_amount ?? 0));
    }

    /**
     * A Coin Recharge variation: `orders.quantity` is the real number of coins
     * the customer typed, charged at `price` per pricingUnit() coins.
     */
    public function isCoin(): bool
    {
        return (int) optional($this->product)->product_type_id === Product::TYPE_COIN;
    }

    /**
     * Whether `price` is the price of pricingUnit() units and the customer types
     * the real quantity, so the order total is prorated (coinTotal()): Coin
     * Recharge (per 1,000 coins) and Social Media Services (per 1,000 followers,
     * views, …). Everything else is `price × quantity`.
     */
    public function isProrated(): bool
    {
        return in_array(
            (int) optional($this->product)->product_type_id,
            [Product::TYPE_COIN, Product::TYPE_SOCIAL],
            true
        );
    }

    /**
     * Order total for `$coins` coins at `$price` per `$unit` coins, rounded UP to
     * the cent so no amount, however small, is ever charged $0.00. The storefront
     * mirrors this exact formula to display the total.
     */
    public static function coinTotal(float $price, int $coins, int $unit): float
    {
        return ceil(round($price * $coins / max(1, $unit) * 100, 6)) / 100;
    }

    /**
     * Smallest `orders.quantity` this variation may be ordered in, never below 1
     * (coins for a coin variation, units otherwise).
     *
     * The larger of the admin's `min_quantity` (in the same unit) and the
     * supplier's own floor, `external_qty_values.min`.
     *
     * Enforced by OrderController::saveOrder and shipped to the storefront as
     * `min_order_quantity`.
     */
    public function minOrderQuantity(): int
    {
        $min = max(1, (int) ($this->min_quantity ?? 0));

        $supplierMin = $this->supplierQtyBound('min');
        if ($supplierMin !== null) {
            $min = max($min, (int) ceil($supplierMin));
        }

        return $min;
    }

    /**
     * Largest `orders.quantity` one order may hold: the smallest of the admin's
     * `max_quantity`, the supplier's `external_qty_values.max` when it reports
     * one, and our own safety limit (OrderController::MAX_COINS for prorated
     * coin / social-media quantities, MAX_QUANTITY otherwise).
     */
    public function maxOrderQuantity(): int
    {
        $cap = $this->isProrated()
            ? \App\Http\Controllers\OrderController::MAX_COINS
            : \App\Http\Controllers\OrderController::MAX_QUANTITY;

        if ((int) ($this->max_quantity ?? 0) > 0) {
            $cap = min($cap, (int) $this->max_quantity);
        }

        $supplierMax = $this->supplierQtyBound('max');
        if ($supplierMax !== null) {
            $cap = min($cap, max(1, (int) floor($supplierMax)));
        }

        return $cap;
    }

    /**
     * external_qty_values.{min|max} converted to our order units. The supplier
     * counts in its own qty; only the Yassen-platform connectors (Yassen, Allo
     * Sharejle) scale ours (they are sent `quantity × {KEY}_QTY_MULTIPLIER`),
     * every other connector sends 1:1.
     */
    private function supplierQtyBound(string $key): ?float
    {
        $qty = $this->external_qty_values;
        if (!is_array($qty) || !isset($qty[$key]) || !is_numeric($qty[$key]) || $qty[$key] <= 0) {
            return null;
        }

        $value = (float) $qty[$key];
        foreach (['yassen', 'allosharejle'] as $source) {
            $multiplier = (float) config("services.{$source}.qty_multiplier", 1);
            // Only touch the parent product when the multiplier can change the answer.
            if ($multiplier > 0 && $multiplier != 1 && $this->supplierSource() === $source) {
                $value /= $multiplier;
            }
        }

        return $value;
    }

    public function getMinOrderQuantityAttribute(): int
    {
        return $this->minOrderQuantity();
    }

    public function getMaxOrderQuantityAttribute(): int
    {
        return $this->maxOrderQuantity();
    }

    public $appends = ['full_path', 'min_order_quantity', 'max_order_quantity'];

    /**
     * Kept: the storefront reads the serialised `price_variations` key to render
     * business-tier pricing, so this relation must be present on every variation
     * payload. (Eloquent snake_cases relation keys on serialisation, which is why
     * `priceVariations` arrives as `price_variations`.)
     */
    protected $with = ['priceVariations'];

    /* End custom functions */
}
