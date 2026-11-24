<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets staff gather supplier rows under one product of their own (every Swift
 * "YouTube …" row as a plan of a single "YouTube" product) without the catalog
 * sync moving each row back to its per-row product within the hour.
 *
 * `manual_product` flips on when anyone but the sync moves a supplier variation
 * to another product (App\Observers\VariationPlacementObserver); from then on
 * SupplierCatalogSync keeps the row's cost, price and stock current where it is.
 * Unticking it hands the row back: the next sync returns it to its own product.
 *
 * A plain boolean round-trips through the CMS's type re-assertion, so it needs no
 * SchemaManifest entry, but it must be registered in cms_pages.fields or the next
 * Products Variations page save drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'manual_product')) {
                $table->boolean('manual_product')->default(false)->nullable()->after('product_id');
            }
        });

        $this->addField('products-variations', [
            'name' => 'manual_product',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Keep in this product. Set automatically when you move a supplier variation to another product. Untick to send it back to its own supplier product on the next sync.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
    }

    public function down(): void
    {
        $this->removeField('products-variations', 'manual_product');

        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'manual_product')) {
                $table->dropColumn('manual_product');
            }
        });
    }
    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (!in_array($field['name'], array_column($fields, 'name'), true)) {
            $fields[] = $field;
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }

    private function removeField(string $route, string $name): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== $name));
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

};
