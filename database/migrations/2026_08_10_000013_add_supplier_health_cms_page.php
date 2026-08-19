<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the supplier health page.
 *
 * As with the KYC queue, the cms_pages row is required rather than cosmetic:
 * AdminMiddleware aborts with 403 for any route it cannot find in the admin's
 * cms_pages map, and super admins skip that check — so without this row the page
 * works for whoever builds it and 403s for every other member of staff.
 */
return new class extends Migration
{
    private const ROUTE = 'supplier-health';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('cms_pages')->insert([
            'icon' => 'fa-plug',
            'display_name' => 'Supplier health',
            'display_name_plural' => 'Supplier health',
            'route' => self::ROUTE,
            'custom_page' => 1,
            'hidden' => 0,
            'parent_title' => 'Suppliers',
            'parent_icon' => 'fa-truck',
            'ht_pos' => (int) DB::table('cms_pages')->max('ht_pos') + 1,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('cms_pages')->where('route', self::ROUTE)->delete();
    }
};
