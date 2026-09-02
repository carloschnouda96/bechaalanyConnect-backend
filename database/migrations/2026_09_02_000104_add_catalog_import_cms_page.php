<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the catalog import/export page.
 *
 * The cms_pages row is required, not decorative: AdminMiddleware aborts 403 for any
 * route it cannot find in the admin's cms_pages map, and super admins skip that check —
 * so without this row the page works perfectly for whoever built it and 403s for every
 * other member of staff.
 *
 * Slotted into the Catalog section, immediately after products-variations. Sidebar
 * grouping is by consecutive ht_pos (AdminMiddleware:107), so everything at or after
 * that position shifts down by one rather than the row simply being appended — appending
 * it would put it in whatever section happens to be last.
 */
return new class extends Migration
{
    private const ROUTE = 'catalog-import';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('route', self::ROUTE)->exists()) {
            return;
        }

        $after = DB::table('cms_pages')->where('route', 'products-variations')->first();
        $position = $after ? (int) $after->ht_pos + 1 : (int) DB::table('cms_pages')->max('ht_pos') + 1;

        DB::table('cms_pages')->where('ht_pos', '>=', $position)->increment('ht_pos');

        DB::table('cms_pages')->insert([
            'icon' => 'fa-upload',
            'display_name' => 'Catalog import',
            'display_name_plural' => 'Catalog import',
            'route' => self::ROUTE,
            'custom_page' => 1,
            'hidden' => 0,
            'parent_title' => $after->parent_title ?? null,
            'parent_icon' => $after->parent_icon ?? null,
            'ht_pos' => $position,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('cms_pages')->where('route', self::ROUTE)->first();

        if (!$row) {
            return;
        }

        DB::table('cms_pages')->where('route', self::ROUTE)->delete();
        DB::table('cms_pages')->where('ht_pos', '>', $row->ht_pos)->decrement('ht_pos');
    }
};
