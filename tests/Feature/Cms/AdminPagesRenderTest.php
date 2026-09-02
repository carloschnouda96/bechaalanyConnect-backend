<?php

namespace Tests\Feature\Cms;

use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsPage;
use Tests\TestCase;

/**
 * Two failure modes that only show up in a browser, and only for the wrong person.
 *
 * 1. A super admin (admin_role_id === null) skips AdminMiddleware's cms_pages check
 *    entirely, so a custom page with no cms_pages row works perfectly for whoever built
 *    it and 403s for every other member of staff. Every other test in this suite acts as
 *    a super admin, so none of them can catch it.
 *
 * 2. Turning on server_side_pagination swaps the vendor list template onto a different
 *    branch — search box, per-page selector, filter funnel and pagination links all
 *    render for the first time. A blade error there takes out the pages staff use most.
 */
class AdminPagesRenderTest extends TestCase
{
    /** Pages the migrations switched to server-side pagination. */
    private const PAGINATED = [
        'orders',
        'credits-transfer',
        'users',
        'products',
        'products-variations',
    ];

    /** Custom pages, which live or die by their cms_pages row. */
    private const CUSTOM = [
        'catalog-import',
        'catalog-pricing',
        'kyc-queue',
        'supplier-health',
        'deleted-records',
    ];

    private function superAdmin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Super';
        $admin->email = 'super_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    /** An admin with a role, i.e. one the cms_pages permission check actually applies to. */
    private function restrictedAdmin(array $routes): Admin
    {
        $role = new AdminRole();
        $role->title = 'Staff ' . uniqid();
        $role->save();

        foreach (CmsPage::whereIn('route', $routes)->get() as $page) {
            $permission = new AdminRolePermission();
            $permission->admin_role_id = $role->id;
            $permission->cms_page_id = $page->id;
            $permission->browse = 1;
            $permission->read = 1;
            $permission->edit = 1;
            $permission->add = 1;
            $permission->delete = 0;
            $permission->save();
        }

        $admin = new Admin();
        $admin->name = 'Staff';
        $admin->email = 'staff_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = $role->id;
        $admin->save();

        return $admin->refresh();
    }

    private function url(string $route): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $route;
    }

    /** @dataProvider paginatedPages */
    public function test_a_paginated_list_page_renders(string $route): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url($route))
            ->assertOk();
    }

    /** The filter/search/per-page controls only exist on the server-side branch. */
    public function test_the_orders_list_now_offers_search_and_filters(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('orders'))
            ->assertOk()
            ->assertSee('custom_search', false)
            ->assertSee('per_page', false)
            ->assertSee('fa-filter', false);
    }

    /**
     * main.js:213 select2-ifies every select on the page, at width:100% unless the
     * element carries `select2-width-auto`. Lose that class off the bulk-status select
     * and the widget stretches, pushing Apply out of the toolbar row. The class is the
     * whole width fix, and nothing else in a request response can show it, so it is
     * asserted directly. Alignment itself is CSS and is not testable from here.
     */
    public function test_the_bulk_status_select_stays_on_select2s_width_auto_path(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('orders'))
            ->assertOk()
            ->assertSee('select2-width-auto', false)
            ->assertSee('Set selected to', false);
    }

    /**
     * The toolbar CSS is registered from inside an @include, nested in the page's own
     * @section('dashboard-content'). If that ever stops reaching layouts/main's
     * @yield('styles') the row silently goes back to being misaligned, with no error.
     */
    public function test_the_bulk_status_toolbar_css_reaches_the_document_head(): void
    {
        $response = $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('orders'))
            ->assertOk()
            ->assertSee('.bulk-status .select2-container', false);

        $head = substr($response->getContent(), 0, strpos($response->getContent(), '</head>'));

        $this->assertStringContainsString('.bulk-status .select2-container', $head);
    }

    /** @dataProvider customPages */
    public function test_a_custom_page_renders_for_a_super_admin(string $route): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url($route))
            ->assertOk();
    }

    /**
     * The one that matters: a role-limited admin must not get a 403 from
     * AdminMiddleware for want of a cms_pages row.
     *
     * @dataProvider customPages
     */
    public function test_a_custom_page_renders_for_a_restricted_admin(string $route): void
    {
        $admin = $this->restrictedAdmin(array_merge(self::CUSTOM, self::PAGINATED));

        $this->actingAs($admin, 'admin')
            ->get($this->url($route))
            ->assertOk();
    }

    /** A page the role was not granted must still be refused. */
    public function test_a_page_outside_the_role_is_refused(): void
    {
        $admin = $this->restrictedAdmin(['orders']);

        $this->actingAs($admin, 'admin')
            ->get($this->url('catalog-pricing'))
            ->assertForbidden();
    }

    public static function paginatedPages(): array
    {
        return array_map(fn ($route) => [$route], self::PAGINATED);
    }

    public static function customPages(): array
    {
        return array_map(fn ($route) => [$route], self::CUSTOM);
    }
}
