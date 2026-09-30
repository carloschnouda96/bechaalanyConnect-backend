<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the Price matrix page (public + per-user-type price for every variation).
 *
 * As with every other custom page, the cms_pages row is what stops AdminMiddleware
 * aborting 403 for non-super admins. Placed directly after catalog-pricing so the
 * Catalog section stays one contiguous run of ht_pos values — the sidebar groups by
 * consecutive run, not by key.
 */
return new class extends Migration
{
    private const ROUTE = 'price-matrix';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('route', self::ROUTE)->exists()) {
            return;
        }

        $after = DB::table('cms_pages')->where('route', 'catalog-pricing')->first();
        $position = $after ? (int) $after->ht_pos + 1 : (int) DB::table('cms_pages')->max('ht_pos') + 1;

        DB::table('cms_pages')->where('ht_pos', '>=', $position)->increment('ht_pos');

        DB::table('cms_pages')->insert([
            'icon' => 'fa-table',
            'display_name' => 'Price matrix',
            'display_name_plural' => 'Price matrix',
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
