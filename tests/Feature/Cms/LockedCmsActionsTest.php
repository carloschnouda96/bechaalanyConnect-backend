<?php

namespace Tests\Feature\Cms;

use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The CMS actions that must not run, whoever asks, and the fields that must not show.
 *
 * cms_pages.add/delete only hide buttons from role admins; the routes in routes/cms.php
 * (LockedCmsActionController) are what make the refusal hold for a super admin too.
 */
class LockedCmsActionsTest extends TestCase
{
    private function superAdmin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Super';
        $admin->email = 'locked_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    public function test_an_order_cannot_be_created_from_the_cms(): void
    {
        $admin = $this->superAdmin();
        $before = DB::table('orders')->count();

        $this->actingAs($admin, 'admin')->get($this->url('orders/create'))->assertForbidden();
        $this->actingAs($admin, 'admin')->post($this->url('orders'), ['quantity' => 1])->assertForbidden();

        $this->assertSame($before, DB::table('orders')->count());
    }

    public function test_a_status_the_code_depends_on_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->delete($this->url('statuses/1'))
            ->assertForbidden();

        $this->assertTrue(DB::table('statuses')->where('id', 1)->exists());
    }

    public function test_a_product_type_cannot_be_created_or_deleted(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->get($this->url('product-type/create'))->assertForbidden();
        $this->actingAs($admin, 'admin')->delete($this->url('product-type/2'))->assertForbidden();
    }

    public function test_a_status_can_still_be_opened_for_renaming(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('statuses/1/edit'))
            ->assertOk();
    }

    public function test_user_credentials_are_off_the_users_list_and_form(): void
    {
        $fields = collect(json_decode(CmsPage::where('route', 'users')->value('fields'), true))->keyBy('name');

        foreach (['password_reset_token', 'verification_token', 'account_verification_code', 'google_id'] as $name) {
            $this->assertSame(1, (int) $fields[$name]['hide_index'], "{$name} must not be a list column");
            $this->assertSame(1, (int) $fields[$name]['hide_edit'], "{$name} must not be editable");
        }

        foreach (['total_purchases', 'received_amount'] as $name) {
            $this->assertSame(1, (int) $fields[$name]['hide_edit'], "{$name} is a running total, not an input");
        }

        // Hidden, never unregistered: a CMS save drops any column missing from this list.
        $this->assertTrue($fields->has('password_reset_token'));
        $this->assertFalse($fields->has('is_business_user'));
        $this->assertFalse(Schema::hasColumn('users', 'is_business_user'));
    }

    public function test_what_an_order_charged_is_read_only(): void
    {
        $fields = collect(json_decode(CmsPage::where('route', 'orders')->value('fields'), true))->keyBy('name');

        foreach (['users_id', 'product_variation_id', 'quantity', 'total_price'] as $name) {
            $this->assertSame(1, (int) $fields[$name]['hide_edit'], "{$name} must be read-only once charged");
        }
        $this->assertSame(0, (int) $fields['statuses_id']['hide_edit'], 'status still drives approve/reject');
    }

    public function test_only_one_pricing_page_is_in_the_sidebar(): void
    {
        $this->assertFalse(CmsPage::where('route', 'catalog-pricing')->exists());
        $this->assertSame(1, (int) CmsPage::where('route', 'product-price-variations')->value('hidden'));
        $this->assertSame(0, (int) CmsPage::where('route', 'price-matrix')->value('hidden'));
    }
}
