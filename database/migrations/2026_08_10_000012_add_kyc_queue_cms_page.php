<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the KYC review queue as a custom CMS page.
 *
 * The row is not decoration. AdminMiddleware (:142) does
 *
 *     if (!isset($admin['cms_pages'][$route])) abort(403);
 *
 * for every request, so without a cms_pages row any admin who has a role would be
 * refused the page outright. Super admins (admin_role_id === null) skip that block,
 * which means the bug is completely invisible while testing as a super admin and
 * only appears for the staff who actually do the reviewing.
 *
 * custom_page = 1 means the vendor does not generate CRUD routes for it and
 * CmsPagesController::edit refuses to schema-edit it — the page is entirely ours.
 */
return new class extends Migration
{
    private const ROUTE = 'kyc-queue';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('route', self::ROUTE)->exists()) {
            return;
        }

        DB::table('cms_pages')->insert([
            'icon' => 'fa-id-card',
            'display_name' => 'KYC Review',
            'display_name_plural' => 'KYC Review',
            'route' => self::ROUTE,
            'custom_page' => 1,
            'hidden' => 0,
            'parent_title' => 'Users',
            'parent_icon' => 'fa-users',
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
