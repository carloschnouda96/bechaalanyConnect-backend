<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the "Deleted records" page.
 *
 * orders, credits_transfer and users soft-delete (2026_08_10_000009), and the vendor
 * CMS strips only the `cms_draft_flag` global scope from its queries — never the
 * SoftDeletingScope, and it calls withTrashed() nowhere. So a record deleted from the
 * CMS vanished from the admin's view while the row stayed in the table, still holding
 * every RESTRICT foreign key pointed at it. That is what made a product variation
 * undeletable with a raw SQLSTATE 1451 and no way to see why.
 *
 * As with the KYC queue and supplier health, the cms_pages row is required rather than
 * cosmetic: AdminMiddleware aborts 403 for any route it cannot find in the admin's
 * cms_pages map, and super admins skip that check — so without this row the page works
 * for whoever builds it and 403s for every other member of staff.
 */
return new class extends Migration
{
    private const ROUTE = 'deleted-records';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('cms_pages')->insert([
            'icon' => 'fa-trash',
            'display_name' => 'Deleted record',
            'display_name_plural' => 'Deleted records',
            'route' => self::ROUTE,
            'custom_page' => 1,
            'hidden' => 0,
            // Top-level, not nested under Users: the page lists deleted orders and
            // credit transfers as well as deleted users.
            'parent_title' => null,
            'parent_icon' => null,
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
