<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns on search, filters, per-page and export for the pages staff actually work in.
 *
 * `server_side_pagination = 0` does far more than skip pagination. In the vendor's list
 * template the search box, the per-page selector, the filter funnel AND the export
 * buttons are all inside a single `@if ($page['server_side_pagination'])`
 * (cms-page/index.blade.php), and the query falls through to `->get()`
 * (CmsPageController::index) — so every row of the table is loaded and rendered on every
 * page view, with no way to filter. There was no way to list only pending orders.
 *
 * The vendor auto-derives the filter set from each page's `select` / `select multiple`
 * fields, so flipping this one flag yields, at no further cost:
 *
 *   orders               customer, product variation, STATUS
 *   credits_transfer     customer, credit type, STATUS
 *   products             subcategory, product type
 *   products_variations  product
 *   users                user type, verification status
 *
 * Done as a data migration and NOT through the CMS page editor on purpose: saving a page
 * schema there runs editDatabase(), which re-asserts every column type from an
 * argument-less Blueprint call and forces every column nullable. This touches no schema.
 *
 * Note the vendor's free-text search skips `select` fields, so searching Orders by
 * customer name does not work — that is a users_id relation. Use the customer filter.
 */
return new class extends Migration
{
    private const TABLES = [
        'orders',
        'credits_transfer',
        'users',
        'products',
        'products_variations',
    ];

    public function up(): void
    {
        DB::table('cms_pages')
            ->whereIn('database_table', self::TABLES)
            ->update([
                'server_side_pagination' => 1,
                'with_export' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('cms_pages')
            ->whereIn('database_table', self::TABLES)
            ->update([
                'server_side_pagination' => 0,
                // users had with_export = 1 before this migration; the others had 0.
                'with_export' => DB::raw("IF(database_table = 'users', 1, 0)"),
                'updated_at' => now(),
            ]);
    }
};
