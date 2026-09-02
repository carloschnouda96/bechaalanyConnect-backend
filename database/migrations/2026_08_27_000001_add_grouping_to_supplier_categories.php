<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-supplier-category "import as ONE product" switch.
 *
 * SupplierCatalogSync has always created one Product + one ProductsVariation per
 * supplier row. That is right for a catalog of independent services (Yassen cards,
 * Perfect Panel SMM services), but wrong for a category that is really one product
 * sold in several sizes: usharez's `usharez-quota` imported "5.5 GB", "11 GB",
 * "16.5 GB", "22 GB", "33 GB" and "45 GB" as six separate storefront products,
 * when they should be six entries in one product's amount dropdown.
 *
 * With this flag set, the sync maintains a single grouped product per supplier
 * category and attaches every supplier row to it as a variation. Default 0, so no
 * existing category changes behaviour.
 *
 * REGISTERED AS A CMS FIELD IN THE SAME MIGRATION, DELIBERATELY. The hellotree CMS
 * drops every column of a page's table that is absent from `cms_pages.fields` on
 * schema-save (CmsPagesController.php:454-472) — an unregistered column is one
 * admin "Save" away from deletion. `boolean` is the correct migration_type: the
 * type-reversion loop (:382-390) re-asserts it from an argument-less Blueprint
 * call, and bare `boolean()` reproduces `tinyint(1)` exactly. Mirrors
 * 2026_07_28_000001 and 2026_08_10_000002.
 */
return new class extends Migration
{
    private const ROUTE = 'supplier-categories';

    private const COLUMN = 'group_as_single_product';

    public function up(): void
    {
        Schema::table('supplier_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_categories', self::COLUMN)) {
                // nullable() to match what the column will BE, not what we'd like:
                // the CMS re-asserts every registered column as nullable on every
                // page save (CmsPagesController.php:388 — both branches of that
                // ternary say 'nullable'), so declaring NOT NULL here would only
                // produce schema drift on the first save. The DEFAULT survives, and
                // SupplierCategory casts the column to boolean, so a NULL reads
                // false. Same treatment as the neighbouring `import_enabled`.
                $table->boolean(self::COLUMN)->nullable()->default(0)->after('import_enabled');
            }
        });

        $this->addField(self::ROUTE, [
            'name' => self::COLUMN,
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Import this category as ONE product, with each supplier product '
                . 'as a variation in the amount dropdown, instead of a separate product per '
                . 'supplier product. Use it for a category that is really one product in several '
                . 'sizes (e.g. internet quota bundles). The grouped product is created on the next '
                . 'sync and is then yours to rename, retype and illustrate in the CMS — the sync '
                . 'never overwrites those.',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
    }

    public function down(): void
    {
        $this->removeField(self::ROUTE, self::COLUMN);

        Schema::table('supplier_categories', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_categories', self::COLUMN)) {
                $table->dropColumn(self::COLUMN);
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
        if (!in_array($field['name'], array_column($fields, 'name'))) {
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
