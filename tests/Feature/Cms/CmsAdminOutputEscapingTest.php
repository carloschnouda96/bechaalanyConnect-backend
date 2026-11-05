<?php

namespace Tests\Feature\Cms;

use App\SupplierCategory;
use Hellotreedigital\Cms\Models\Admin;
use Tests\TestCase;

/**
 * Stored names reach CMS pages from supplier feeds and from customers (usernames).
 * Regressions from the 2026-11 security audit (run-2).
 */
class CmsAdminOutputEscapingTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

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

    public function test_the_reorder_page_prints_a_supplier_name_as_text(): void
    {
        SupplierCategory::create([
            'source' => 'yassen',
            'external_id' => 'xss-' . uniqid(),
            'name' => self::PAYLOAD,
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get('/admin/supplier-categories/order')
            ->assertOk()
            ->assertDontSee(self::PAYLOAD, false)
            ->assertSee(e(self::PAYLOAD), false);
    }

    /**
     * The handler in the vendor main.js concatenated select2's decoded label into HTML.
     * vendor/ is not deployed from this repo, so the CMS must load an app-owned copy.
     */
    public function test_the_cms_loads_a_main_js_that_inserts_option_labels_as_text(): void
    {
        $scripts = config('hellotree.cms_assets.scripts');

        $this->assertNotContains('asset?path=js/main.js', $scripts);
        $this->assertContains('cms-assets/js/main.js', $scripts);

        $source = file_get_contents(public_path('cms-assets/js/main.js'));
        $this->assertStringNotContainsString("+ label +", $source);
        $this->assertStringContainsString('.text(label)', $source);
    }
}
