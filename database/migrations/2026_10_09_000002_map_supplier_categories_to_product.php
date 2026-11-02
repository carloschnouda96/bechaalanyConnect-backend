<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an admin import a supplier category into a product THEY created.
 *
 * `supplier_categories.product_id`: when set, every row of that supplier category
 * becomes a variation of that product (e.g. 1xpanel "Instagram - Likes (USA)" →
 * the admin's "Likes" product under Social Media › Instagram). Several supplier
 * categories may point at the same product.
 *
 * `products_variations.external_source`: the supplier that owns a variation. Until
 * now that was only known from the PARENT product, and SupplierOrderFulfillment
 * bailed when the parent had no `external_source` — so a supplier variation living
 * under an admin-made product would be charged and never placed. Backfilled from
 * the parent for every existing supplier variation.
 *
 * Both columns are registered as CMS fields in this migration, for the same reason
 * as 2026_08_27_000001: a CMS schema-save drops every unregistered column.
 * `product_id` is a `select` (skipped by the type-reversion loop); `external_source`
 * is a read-only `string`, which round-trips to varchar(191).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_categories', 'product_id')) {
                // int unsigned, to match products.id (and category_id/subcategory_id).
                $table->unsignedInteger('product_id')->nullable()->after('subcategory_id');
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            }
        });

        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'external_source')) {
                $table->string('external_source')->nullable()->after('external_id');
                $table->index(['external_source', 'external_id'], 'products_variations_external_source_id_index');
            }
        });

        DB::statement(
            'UPDATE products_variations v JOIN products p ON p.id = v.product_id
             SET v.external_source = p.external_source
             WHERE v.external_id IS NOT NULL AND p.external_source IS NOT NULL AND v.external_source IS NULL'
        );

        $this->addField('supplier-categories', [
            'name' => 'product_id',
            'migration_type' => 'bigInteger',
            'form_field' => 'select',
            'form_field_additionals_1' => 'products',
            'form_field_additionals_2' => 'name',
            'description' => 'Optional: import every product of this supplier category as a variation of this '
                . 'product (e.g. all "Instagram - Likes" services into your "Likes" product). Several supplier '
                . 'categories can share one product. Overrides Category, Subcategory and "group as single product". '
                . 'Pick a product you created yourself, whose Product Type matches the supplier\'s '
                . '(Social Media Service for followers / likes / views) — otherwise the sync skips the rows and logs why.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ], 'subcategory_id');

        $this->addField('products-variations', [
            'name' => 'external_source',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier that fulfils this variation. Set by the catalog sync — do not edit.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ], 'external_id');
    }

    public function down(): void
    {
        $this->removeField('supplier-categories', 'product_id');
        $this->removeField('products-variations', 'external_source');

        Schema::table('supplier_categories', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_categories', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            }
        });

        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'external_source')) {
                $table->dropIndex('products_variations_external_source_id_index');
                $table->dropColumn('external_source');
            }
        });
    }

    /** Insert the field right after $after, so the CMS form keeps a sensible order. */
    private function addField(string $route, array $field, ?string $after = null): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (in_array($field['name'], array_column($fields, 'name'))) {
            return;
        }

        $index = $after !== null ? array_search($after, array_column($fields, 'name')) : false;
        if ($index === false) {
            $fields[] = $field;
        } else {
            array_splice($fields, $index + 1, 0, [$field]);
        }

        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
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
