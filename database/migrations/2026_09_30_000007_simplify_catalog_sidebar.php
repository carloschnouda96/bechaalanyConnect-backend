<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders the Catalog section the way a product is actually set up, and names each
 * page for what it does:
 *
 *   Categories → Subcategories → Products → Variations → Prices → Import / Export (CSV)
 *   → Supplier imports
 *
 * The product page is now where a product's variations and prices are managed
 * (Cms\ProductEditorController), so the rest of the section reads as tools around it.
 *
 * Only Product Type is hidden. `cms_pages.hidden` does more than hide a menu entry:
 * AdminMiddleware gives role admins NO permissions at all on a hidden page, so hiding
 * Products Variations would 403 every "edit variation" link for staff. Product Type is
 * safe to hide — its rows are reference data (ids hardcoded, add/delete already
 * refused by LockedCmsActionController) and the product form reads it without a
 * permission check. Product Price Variations stays hidden, as before.
 *
 * Supplier imports also gets server-side pagination, which is what brings back the
 * search box and per-page selector for its ~600 rows (they sit inside the same @if in
 * the vendor list template), and its Import field says what to do next.
 *
 * Catalog keeps the same ht_pos range (10–18), so no other section moves, and stays
 * contiguous — AdminMiddleware groups the sidebar by consecutive run.
 */
return new class extends Migration
{
    /** route => [ht_pos, display_name, display_name_plural, hidden] */
    private const CATALOG = [
        'categories' => [10, 'Category', 'Categories', 0],
        'subcategories' => [11, 'Subcategory', 'Subcategories', 0],
        'products' => [12, 'Product', 'Products', 0],
        'products-variations' => [13, 'Variation', 'Variations', 0],
        'price-matrix' => [14, 'Prices', 'Prices', 0],
        'catalog-import' => [15, 'Import / Export (CSV)', 'Import / Export (CSV)', 0],
        'supplier-categories' => [16, 'Supplier import', 'Supplier imports', 0],
        'product-type' => [17, 'Product Type', 'Product Type', 1],
        'product-price-variations' => [18, 'Product Price Variation', 'Product Price Variations', 1],
    ];

    /** The layout before this migration, so down() is a real restore. */
    private const PREVIOUS = [
        'products' => [10, 'Product', 'Products', 0],
        'products-variations' => [11, 'Products Variation', 'Products Variations', 0],
        'catalog-import' => [12, 'Catalog import', 'Catalog import', 0],
        'price-matrix' => [13, 'Price matrix', 'Price matrix', 0],
        'product-price-variations' => [14, 'Product Price Variation', 'Product Price Variations', 1],
        'product-type' => [15, 'Product Type', 'Product Type', 0],
        'categories' => [16, 'Category', 'Categories', 0],
        'subcategories' => [17, 'Subcategory', 'Subcategories', 0],
        'supplier-categories' => [18, 'Supplier Category', 'Supplier Categories', 0],
    ];

    private const IMPORT_ENABLED_DESCRIPTION = 'Tick, save, then press <strong>Sync now</strong> above to import this category\'s products straight away (otherwise they arrive with the next hourly sync).';
    private const IMPORT_ENABLED_PREVIOUS = 'Enable to import this category\'s products into the catalog on the next sync.';

    public function up(): void
    {
        $this->apply(self::CATALOG);

        DB::table('cms_pages')->where('route', 'supplier-categories')->update(['server_side_pagination' => 1]);
        $this->describeImportEnabled(self::IMPORT_ENABLED_DESCRIPTION);
    }

    public function down(): void
    {
        $this->apply(self::PREVIOUS);

        DB::table('cms_pages')->where('route', 'supplier-categories')->update(['server_side_pagination' => 0]);
        $this->describeImportEnabled(self::IMPORT_ENABLED_PREVIOUS);
    }

    private function apply(array $layout): void
    {
        foreach ($layout as $route => [$position, $name, $plural, $hidden]) {
            DB::table('cms_pages')->where('route', $route)->update([
                'ht_pos' => $position,
                'display_name' => $name,
                'display_name_plural' => $plural,
                'hidden' => $hidden,
                'parent_title' => 'Catalog',
                'parent_icon' => 'fa-cubes',
                'updated_at' => now(),
            ]);
        }
    }

    /** Only the description changes; every other key of the field is left as it is. */
    private function describeImportEnabled(string $description): void
    {
        $page = DB::table('cms_pages')->where('route', 'supplier-categories')->first(['id', 'fields']);
        $fields = $page ? json_decode($page->fields, true) : null;

        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as &$field) {
            if (($field['name'] ?? null) === 'import_enabled') {
                $field['description'] = $description;
            }
        }
        unset($field);

        DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields)]);
    }
};
