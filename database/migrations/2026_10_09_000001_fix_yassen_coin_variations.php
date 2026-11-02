<?php

use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\SupplierCatalogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Yassen `amount` products (Jawaker, SoulChill…) are priced PER COIN
     * ($0.000105 for Jawaker) and take `qty` = coins. Before 2026-10-09 the sync
     * stored them as Direct Recharge with `price = round(cost × markup, 2)` — $0.00,
     * i.e. sellable for free — and an admin-made coin product sent Yassen a block
     * count instead of coins.
     *
     * Brings existing rows onto the coin model the sync now seeds for new ones:
     * parent product → Coin Recharge (4), `unit_amount` =
     * SupplierCatalogSync::coinPricingUnit() so `price` means "per 10,000 coins"
     * (or whatever power of 10 makes it at least $1). Setting unit_amount reprices non-manual rows through
     * ProductsVariationObserver (and their tier % rows). Rows an admin already set
     * up by hand are left alone and listed for review.
     */
    public function up(): void
    {
        $variations = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->where('external_type', 'amount')
            ->get()
            ->filter(fn ($v) => optional($v->product)->external_source === 'yassen');

        foreach ($variations as $variation) {
            $product = $variation->product;
            $label = "variation #{$variation->id} (product #{$product->id})";

            if ((int) $product->product_type_id === Product::TYPE_USER_ID) {
                $product->product_type_id = Product::TYPE_COIN;
                $product->save();
                $this->report("{$label}: product type → Coin Recharge");
            } elseif ((int) $product->product_type_id !== Product::TYPE_COIN) {
                $this->report("{$label}: product type {$product->product_type_id} left as is — REVIEW");
                continue;
            }

            if ($variation->unit_amount) {
                $this->report("{$label}: unit_amount already {$variation->unit_amount}, price {$variation->price} is now per {$variation->unit_amount} coins — REVIEW");
                continue;
            }

            if ($variation->manual_price) {
                $this->report("{$label}: hand-set price {$variation->price} with no unit_amount — REVIEW (it is now the price of ONE coin)");
                continue;
            }

            $cost = (float) ($variation->cost_price ?? $variation->external_price ?? 0);
            if ($cost <= 0) {
                $this->report("{$label}: no cost recorded — REVIEW");
                continue;
            }

            $before = $variation->price;
            $variation->unit_amount = SupplierCatalogSync::coinPricingUnit($cost);
            ProductsVariation::applyingSystemPricing(fn () => $variation->save());
            $this->report("{$label}: unit_amount → {$variation->unit_amount}, price {$before} → {$variation->price} per {$variation->unit_amount}");
        }
    }

    public function down(): void
    {
        // Data fix — reverting would put the free $0.00 prices back.
    }

    private function report(string $line): void
    {
        Log::info('[fix_yassen_coin_variations] ' . $line);
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            echo '  ' . $line . PHP_EOL;
        }
    }
};
