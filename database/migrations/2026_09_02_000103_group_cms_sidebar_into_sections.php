<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns a flat wall of ~35 sidebar entries into six labelled sections.
 *
 * The ordering is not cosmetic. AdminMiddleware builds the sidebar by walking
 * CmsPage::orderBy('ht_pos') and starting a new group only when a page's
 * parent_title/parent_icon differ from the PREVIOUS page (AdminMiddleware:107) — the
 * grouping is by consecutive run, not by key. So every member of a section has to be
 * contiguous in ht_pos or the section is rendered more than once.
 *
 * That is already happening today: kyc-queue carries parent_title 'Users' but sits at
 * ht_pos 100, thirty rows below users/user-types, so "Users" appears twice in the
 * sidebar. Assigning contiguous positions here fixes that as a side effect.
 *
 * Pages a non-super admin cannot browse are skipped by that same loop without updating
 * $last_page_added, so a permission-restricted admin still sees unbroken sections.
 *
 * Order within each section is by how often staff need it: the daily approval queues
 * first, one-off configuration last.
 */
return new class extends Migration
{
    /** section => [icon, [route, ...]] — array order is the sidebar order. */
    private const SECTIONS = [
        'Sales' => ['fa-shopping-cart', [
            'orders',
            'credits-transfer',
            'credits-types',
            'statuses',
        ]],
        'Customers' => ['fa-users', [
            'users',
            'kyc-queue',
            'user-types',
            'user-notifications',
            'verification-statuses',
        ]],
        'Catalog' => ['fa-cubes', [
            'products',
            'products-variations',
            'catalog-import',
            'catalog-pricing',
            'product-price-variations',
            'product-type',
            'categories',
            'subcategories',
            'supplier-categories',
        ]],
        'Suppliers' => ['fa-truck', [
            'supplier-health',
        ]],
        'Content' => ['fa-file-text-o', [
            'homepage-settings',
            'banner-swiper',
            'about-page-settings',
            'contact-page-settings',
            'contact-details',
            'contact-form-subjects',
            'contact-form-request',
            'menu-items',
            'dashboard-menu-items',
            'dashboard-page-settings',
            'social-media-links',
            'seo-pages',
        ]],
        'Settings' => ['fa-cog', [
            'fixed-settings',
            'countries',
            'languages',
            'logging-pages-settings',
            'deleted-records',
            'cms-pages',
        ]],
        'Admins' => ['fa-user-secret', [
            'admin-roles',
            'admins',
            'logs',
        ]],
    ];

    /** The layout before this migration, so down() is a real restore. */
    private const PREVIOUS = [
        'cms-pages' => [1, null, null],
        'languages' => [2, null, null],
        'admin-roles' => [3, 'Admins', 'fa-user-secret'],
        'admins' => [4, 'Admins', 'fa-user-secret'],
        'logs' => [5, 'Admins', 'fa-user-secret'],
        'seo-pages' => [6, null, null],
        'users' => [7, 'Users', 'fa-users'],
        'user-types' => [8, 'Users', 'fa-users'],
        'menu-items' => [9, null, null],
        'fixed-settings' => [10, null, null],
        'banner-swiper' => [11, null, null],
        'social-media-links' => [12, null, null],
        'homepage-settings' => [13, null, null],
        'categories' => [14, null, null],
        'subcategories' => [15, null, null],
        'products' => [16, null, null],
        'products-variations' => [17, null, null],
        'countries' => [18, null, null],
        'about-page-settings' => [19, null, null],
        'contact-details' => [20, null, null],
        'contact-page-settings' => [21, null, null],
        'contact-form-subjects' => [22, null, null],
        'contact-form-request' => [23, null, null],
        'product-type' => [24, null, null],
        'orders' => [25, null, null],
        'credits-types' => [26, null, null],
        'credits-transfer' => [27, null, null],
        'statuses' => [28, null, null],
        'product-price-variations' => [29, null, null],
        'dashboard-menu-items' => [30, null, null],
        'logging-pages-settings' => [31, null, null],
        'user-notifications' => [32, null, null],
        'dashboard-page-settings' => [33, null, null],
        'verification-statuses' => [99, null, null],
        'supplier-categories' => [99, null, null],
        'kyc-queue' => [100, 'Users', 'fa-users'],
        'supplier-health' => [101, 'Suppliers', 'fa-truck'],
        'deleted-records' => [102, null, null],
    ];

    public function up(): void
    {
        $position = 1;

        foreach (self::SECTIONS as $title => [$icon, $routes]) {
            foreach ($routes as $route) {
                DB::table('cms_pages')->where('route', $route)->update([
                    'parent_title' => $title,
                    'parent_icon' => $icon,
                    'ht_pos' => $position,
                    'updated_at' => now(),
                ]);
                $position++;
            }
        }

        // Anything this migration does not know about (a page added later, or by a
        // different install) keeps working — it just lands after the sections rather
        // than being silently dragged into the last one.
        $known = array_merge(...array_map(fn ($s) => $s[1], array_values(self::SECTIONS)));

        foreach (DB::table('cms_pages')->whereNotIn('route', $known)->orderBy('ht_pos')->get(['id']) as $page) {
            DB::table('cms_pages')->where('id', $page->id)->update([
                'parent_title' => null,
                'parent_icon' => null,
                'ht_pos' => $position,
                'updated_at' => now(),
            ]);
            $position++;
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS as $route => [$position, $parentTitle, $parentIcon]) {
            DB::table('cms_pages')->where('route', $route)->update([
                'parent_title' => $parentTitle,
                'parent_icon' => $parentIcon,
                'ht_pos' => $position,
                'updated_at' => now(),
            ]);
        }
    }
};
