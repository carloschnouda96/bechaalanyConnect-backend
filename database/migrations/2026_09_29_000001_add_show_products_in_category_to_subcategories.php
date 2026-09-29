<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds the "list this subcategory's products directly on its category page" flag.
 *
 * `products.subcategory_id` is NOT NULL (FK to subcategories) and every storefront
 * product URL is /categories/{cat}/{sub}/{product}, so a product cannot live on a
 * category without a subcategory. When an admin maps a supplier category to a
 * Category only (no Subcategory, grouping off), SupplierCatalogSync still has to
 * create a subcategory to hold the products — it now flags it, and the storefront
 * treats a flagged subcategory as invisible: its products render on the category
 * page, and its own page redirects there. URLs, search and related products are
 * unaffected.
 *
 * REGISTERED AS A CMS FIELD IN THE SAME MIGRATION, DELIBERATELY — an unregistered
 * column is dropped on the next Subcategories page schema-save. `boolean` because
 * a bare `boolean()` reproduces tinyint(1) on the CMS type-reversion loop.
 * Mirrors 2026_08_27_000001.
 *
 * BACKFILL: subcategories the sync already auto-created for a non-grouped
 * category are flagged. They are recognised by the slug the sync gives them,
 * `{name}-sub-{external_id}` (SupplierCatalogSync::uniqueSlug), so a subcategory
 * an admin picked in the CMS is never touched.
 */
return new class extends Migration
{
    private const ROUTE = 'subcategories';

    private const COLUMN = 'show_products_in_category';

    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (!Schema::hasColumn('subcategories', self::COLUMN)) {
                // nullable(): the CMS re-asserts every registered column as nullable
                // on page save; the DEFAULT survives and the model casts NULL to false.
                $table->boolean(self::COLUMN)->nullable()->default(0)->after('is_active');
            }
        });

        $this->addField(self::ROUTE, [
            'name' => self::COLUMN,
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Hide this subcategory on the storefront and list its products '
                . 'directly on the parent category page. Set automatically when a supplier '
                . 'category is imported into a Category without choosing a Subcategory.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->backfillAutoCreated();
    }

    public function down(): void
    {
        $this->removeField(self::ROUTE, self::COLUMN);

        Schema::table('subcategories', function (Blueprint $table) {
            if (Schema::hasColumn('subcategories', self::COLUMN)) {
                $table->dropColumn(self::COLUMN);
            }
        });
    }

    private function backfillAutoCreated(): void
    {
        $rows = DB::table('supplier_categories')
            ->join('subcategories', 'subcategories.id', '=', 'supplier_categories.subcategory_id')
            ->where(fn ($q) => $q->whereNull('supplier_categories.group_as_single_product')
                ->orWhere('supplier_categories.group_as_single_product', 0))
            ->get(['subcategories.id', 'subcategories.slug', 'supplier_categories.external_id']);

        // Exactly the suffix SupplierCatalogSync::uniqueSlug() appends for a subcategory.
        $ids = $rows
            ->filter(fn ($row) => $row->external_id !== null
                && Str::endsWith((string) $row->slug, '-' . Str::slug('sub-' . $row->external_id)))
            ->pluck('id')
            ->unique()
            ->all();

        if ($ids) {
            DB::table('subcategories')->whereIn('id', $ids)->update([self::COLUMN => 1]);
        }
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
