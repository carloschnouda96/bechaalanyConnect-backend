<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leaves the Price matrix as the one place prices are managed.
 *
 * - Bulk pricing (catalog-pricing) is removed: its "set profit %" is the matrix bulk bar
 *   on the Public column, its "adjust prices by %" is the matrix's `adjust` bulk mode,
 *   and its preview is the matrix's live per-cell price. The ht_pos gap is closed so
 *   the Catalog section stays one contiguous run (the sidebar groups by consecutive run).
 *
 * - Product Price Variations is HIDDEN, not removed. Its table is load-bearing — it holds
 *   every user-type price that OrderController::saveOrder charges — and its model and
 *   registered fields must stay so a CMS schema save cannot drop a column. The vendor's
 *   `hidden` (AdminMiddleware:65) withholds every permission from ROLE admins, so staff
 *   neither see nor open it; super admins still do, like Verification Statuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bulk = DB::table('cms_pages')->where('route', 'catalog-pricing')->first();

        if ($bulk) {
            DB::table('admin_role_permissions')->where('cms_page_id', $bulk->id)->delete();
            DB::table('cms_pages')->where('id', $bulk->id)->delete();
            DB::table('cms_pages')->where('ht_pos', '>', $bulk->ht_pos)->decrement('ht_pos');
        }

        DB::table('cms_pages')->where('route', 'product-price-variations')->update([
            'hidden' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('cms_pages')->where('route', 'product-price-variations')->update([
            'hidden' => 0,
            'updated_at' => now(),
        ]);

        if (DB::table('cms_pages')->where('route', 'catalog-pricing')->exists()) {
            return;
        }

        // Restores the sidebar entry only; the controller and view were deleted with it.
        $matrix = DB::table('cms_pages')->where('route', 'price-matrix')->first();
        $position = $matrix ? (int) $matrix->ht_pos : (int) DB::table('cms_pages')->max('ht_pos') + 1;

        DB::table('cms_pages')->where('ht_pos', '>=', $position)->increment('ht_pos');

        DB::table('cms_pages')->insert([
            'icon' => 'fa-tags',
            'display_name' => 'Bulk pricing',
            'display_name_plural' => 'Bulk pricing',
            'route' => 'catalog-pricing',
            'custom_page' => 1,
            'hidden' => 0,
            'parent_title' => $matrix->parent_title ?? null,
            'parent_icon' => $matrix->parent_icon ?? null,
            'ht_pos' => $position,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }
};
