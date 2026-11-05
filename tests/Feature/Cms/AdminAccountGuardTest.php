<?php

namespace Tests\Feature\Cms;

use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regressions from the 2026-11 security audit (run-2).
 *
 * 1. AdminMiddleware treats every verb but POST/PUT/DELETE as a read, and the vendor's
 *    Route::resource registers PATCH on admins, admin-roles and languages. So a role with
 *    only `read` on Admins could reset any admin's password, unlogged.
 * 2. AdminsController hides super admins from its list but its record actions take any id.
 */
class AdminAccountGuardTest extends TestCase
{
    private function role(array $routes, array $rights): AdminRole
    {
        $role = new AdminRole();
        $role->title = 'Staff ' . uniqid();
        $role->save();

        foreach (CmsPage::whereIn('route', $routes)->get() as $page) {
            $permission = new AdminRolePermission();
            $permission->admin_role_id = $role->id;
            $permission->cms_page_id = $page->id;
            foreach (['browse', 'read', 'edit', 'add', 'delete'] as $right) {
                $permission->{$right} = in_array($right, $rights, true) ? 1 : 0;
            }
            $permission->save();
        }

        return $role;
    }

    private function admin(?int $roleId): Admin
    {
        $admin = new Admin();
        $admin->name = 'Admin';
        $admin->email = 'admin_' . uniqid() . '@example.test';
        $admin->password = bcrypt('Original-Password1');
        $admin->admin_role_id = $roleId;
        $admin->save();

        return $admin->refresh();
    }

    private function payload(Admin $target, int $roleId): array
    {
        return [
            'name' => 'Changed',
            'email' => $target->email,
            'password' => 'Hijacked-Password1',
            'password_confirmation' => 'Hijacked-Password1',
            'admin_role_id' => $roleId,
        ];
    }

    private function assertPasswordUnchanged(Admin $admin): void
    {
        $this->assertTrue(Hash::check('Original-Password1', $admin->refresh()->password));
    }

    public function test_patch_on_an_admin_is_refused_for_a_read_only_role(): void
    {
        $role = $this->role(['admins'], ['browse', 'read']);
        $target = $this->admin($role->id);

        $this->actingAs($this->admin($role->id), 'admin')
            ->patch('/admin/admins/' . $target->id, $this->payload($target, $role->id))
            ->assertForbidden();

        $this->assertPasswordUnchanged($target);
    }

    public function test_method_override_cannot_reach_the_patch_route_either(): void
    {
        $role = $this->role(['admins'], ['browse', 'read']);
        $target = $this->admin($role->id);

        $this->actingAs($this->admin($role->id), 'admin')
            ->post('/admin/admins/' . $target->id, $this->payload($target, $role->id) + ['_method' => 'PATCH'])
            ->assertForbidden();

        $this->assertPasswordUnchanged($target);
    }

    public function test_patch_on_a_role_cannot_grant_new_rights(): void
    {
        $role = $this->role(['admin-roles', 'admins'], ['browse', 'read']);
        $page = CmsPage::where('route', 'admins')->firstOrFail();

        $this->actingAs($this->admin($role->id), 'admin')
            ->patch('/admin/admin-roles/' . $role->id, ['title' => 'x', 'edit_' . $page->id => 1])
            ->assertForbidden();

        $permission = AdminRolePermission::where('admin_role_id', $role->id)->where('cms_page_id', $page->id)->firstOrFail();
        $this->assertEquals(0, $permission->edit);
    }

    public function test_patch_on_a_language_is_refused(): void
    {
        $role = $this->role(['languages'], ['browse', 'read']);

        $this->actingAs($this->admin($role->id), 'admin')
            ->patch('/admin/languages/1', ['slug' => 'xx', 'title' => 't', 'direction' => 'ltr'])
            ->assertForbidden();
    }

    public function test_a_role_with_edit_cannot_change_a_super_admin(): void
    {
        $role = $this->role(['admins'], ['browse', 'read', 'edit']);
        $super = $this->admin(null);

        $this->actingAs($this->admin($role->id), 'admin')
            ->put('/admin/admins/' . $super->id, $this->payload($super, $role->id))
            ->assertForbidden();

        $this->assertPasswordUnchanged($super);
        $this->assertNull($super->refresh()->admin_role_id);
    }

    public function test_a_role_with_delete_cannot_remove_a_super_admin_even_in_a_mixed_list(): void
    {
        $role = $this->role(['admins'], ['browse', 'read', 'delete']);
        $super = $this->admin(null);
        $other = $this->admin($role->id);

        $this->actingAs($this->admin($role->id), 'admin')
            ->delete('/admin/admins/' . $other->id . ',' . $super->id)
            ->assertForbidden();

        $this->assertNotNull(Admin::find($super->id));
        $this->assertNotNull(Admin::find($other->id));
    }

    public function test_a_role_cannot_open_a_super_admin_record(): void
    {
        $role = $this->role(['admins'], ['browse', 'read', 'edit']);
        $super = $this->admin(null);
        $acting = $this->actingAs($this->admin($role->id), 'admin');

        $acting->get('/admin/admins/' . $super->id)->assertForbidden();
        $acting->get('/admin/admins/' . $super->id . '/edit')->assertForbidden();
    }

    public function test_a_role_with_edit_can_still_change_another_role_admin(): void
    {
        $role = $this->role(['admins'], ['browse', 'read', 'edit']);
        $target = $this->admin($role->id);

        $this->actingAs($this->admin($role->id), 'admin')
            ->put('/admin/admins/' . $target->id, $this->payload($target, $role->id))
            ->assertOk();

        $this->assertTrue(Hash::check('Hijacked-Password1', $target->refresh()->password));
    }

    public function test_a_super_admin_can_still_change_and_delete_any_admin(): void
    {
        $role = $this->role(['admins'], ['browse']);
        $target = $this->admin($role->id);
        $otherSuper = $this->admin(null);
        $acting = $this->actingAs($this->admin(null), 'admin');

        $acting->put('/admin/admins/' . $target->id, $this->payload($target, $role->id))->assertOk();
        $acting->delete('/admin/admins/' . $otherSuper->id)->assertRedirect();

        $this->assertNull(Admin::find($otherSuper->id));
    }
}
