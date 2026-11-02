<?php

use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\SupplierCatalogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1xpanel (Perfect Panel `Default` services) as a 1xpanel-style order form:
     *
     *  1. `products_variations.external_details` (json) — the service details the
     *     sync scrapes from the panel's public /services page (Quality, Speed,
     *     Refill, Link, Start time, Drop, notes, Average time). Sync-owned and
     *     read-only; registered on the CMS page, or the next page save drops it.
     *  2. product_type 5 "Social Media Service": a link + a typed quantity,
     *     priced per `unit_amount` (1,000) units — the coin engine's prorated total.
     *  3. Existing 1xpanel categories become grouped (one product, services as
     *     its dropdown), the default the sync now seeds for new ones.
     *  4. Existing 1xpanel `Default` rows move onto that model NOW: they were sold
     *     per unit at a cost of e.g. $0.0029, i.e. a stored price of $0.00, so every
     *     order was free. Hand-priced rows are left alone and reported.
     */
    private const PAGE_ROUTE = 'products-variations';

    public function up(): void
    {
        if (!Schema::hasColumn('products_variations', 'external_details')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->json('external_details')->nullable()->after('external_qty_values');
            });
        }
        $this->registerField();

        DB::table('product_type')->updateOrInsert(['id' => Product::TYPE_SOCIAL], [
            'cms_draft_flag' => 0,
            'slug' => 'social-media-service',
            'title' => 'Social Media Service',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grouped = DB::table('supplier_categories')
            ->where('source', '1xpanel')
            ->where(fn ($q) => $q->whereNull('group_as_single_product')->orWhere('group_as_single_product', 0))
            ->update(['group_as_single_product' => 1, 'updated_at' => now()]);
        $this->report("{$grouped} 1xpanel categories set to import as one product");

        $this->backfillVariations();
    }

    public function down(): void
    {
        // Steps 3–4 are data fixes; reverting 4 would put the free $0.00 prices back.
        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if ($page) {
            $fields = json_decode($page->fields, true) ?: [];
            $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== 'external_details'));
            DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('products_variations', 'external_details')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->dropColumn('external_details');
            });
        }
    }

    private function registerField(): void
    {
        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (in_array('external_details', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'external_details',
            'migration_type' => 'json',
            'form_field' => 'textarea',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Service details shown to customers (quality, speed, refill, link, start time, average time), read from the supplier on every sync. Read-only.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];

        DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    private function backfillVariations(): void
    {
        $variations = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->where('external_type', 'Default')
            ->get()
            ->filter(fn ($v) => optional($v->product)->external_source === '1xpanel');

        foreach ($variations as $variation) {
            $product = $variation->product;
            $label = "variation #{$variation->id} (product #{$product->id})";

            if ((int) $product->product_type_id === Product::TYPE_USER_ID) {
                $product->product_type_id = Product::TYPE_SOCIAL;
                $product->save();
                $this->report("{$label}: product type → Social Media Service");
            } elseif ((int) $product->product_type_id !== Product::TYPE_SOCIAL) {
                $this->report("{$label}: product type {$product->product_type_id} left as is — REVIEW");
                continue;
            }

            if ($variation->unit_amount) {
                continue;
            }

            if ($variation->manual_price) {
                $this->report("{$label}: hand-set price {$variation->price} with no unit_amount — REVIEW (it is the price of ONE unit)");
                continue;
            }

            $cost = (float) ($variation->cost_price ?? $variation->external_price ?? 0);
            if ($cost <= 0) {
                $this->report("{$label}: no cost recorded — REVIEW");
                continue;
            }

            $before = $variation->price;
            // ProductsVariationObserver reprices a derived price (and its tier % rows)
            // when unit_amount changes.
            $variation->unit_amount = SupplierCatalogSync::socialPricingUnit($cost);
            ProductsVariation::applyingSystemPricing(fn () => $variation->save());
            $this->report("{$label}: unit_amount → {$variation->unit_amount}, price {$before} → {$variation->price} per {$variation->unit_amount}");
        }
    }

    private function report(string $line): void
    {
        Log::info('[social_media_services_for_perfect_panel] ' . $line);
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            echo '  ' . $line . PHP_EOL;
        }
    }
};
