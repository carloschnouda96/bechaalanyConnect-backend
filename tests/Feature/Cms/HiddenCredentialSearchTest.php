<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The vendor list search runs LIKE over every field whose form_field is text-like, including
 * ones marked hide_index. A browse-only role could read a hidden token one character at a
 * time from whether a row came back, then take over the account.
 * Regression from the 2026-11 security audit (run-2).
 */
class HiddenCredentialSearchTest extends TestCase
{
    private function browseOnlyAdmin(): Admin
    {
        $role = new AdminRole();
        $role->title = 'Viewer ' . uniqid();
        $role->save();

        $permission = new AdminRolePermission();
        $permission->admin_role_id = $role->id;
        $permission->cms_page_id = CmsPage::where('route', 'users')->firstOrFail()->id;
        $permission->browse = 1;
        foreach (['read', 'edit', 'add', 'delete'] as $right) {
            $permission->{$right} = 0;
        }
        $permission->save();

        $admin = new Admin();
        $admin->name = 'Viewer';
        $admin->email = 'viewer_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = $role->id;
        $admin->save();

        return $admin->refresh();
    }

    private function victim(array $attributes = []): User
    {
        return User::create($attributes + [
            'username' => 'victim_' . uniqid(),
            'email' => 'victim_' . uniqid() . '@example.test',
            'password' => bcrypt('Old-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
    }

    public function test_searching_the_users_list_does_not_match_hidden_tokens(): void
    {
        $victim = $this->victim(['password_reset_token' => 'qqtokenzz' . uniqid()]);

        $this->actingAs($this->browseOnlyAdmin(), 'admin')
            ->get('/admin/users?custom_search=qqtokenzz')
            ->assertOk()
            ->assertDontSee($victim->email);
    }

    public function test_searching_the_users_list_does_not_match_a_hidden_verification_code(): void
    {
        $victim = $this->victim(['account_verification_code' => 918273]);

        $this->actingAs($this->browseOnlyAdmin(), 'admin')
            ->get('/admin/users?custom_search=918273')
            ->assertOk()
            ->assertDontSee($victim->email);
    }

    public function test_search_still_finds_visible_fields(): void
    {
        $victim = $this->victim();

        $this->actingAs($this->browseOnlyAdmin(), 'admin')
            ->get('/admin/users?custom_search=' . urlencode($victim->email))
            ->assertOk()
            ->assertSee($victim->email);
    }

    public function test_a_stored_reset_token_cannot_be_used_as_the_token(): void
    {
        $plain = now()->addHour()->timestamp . '-' . bin2hex(random_bytes(16));
        $stored = hash('sha256', $plain);
        $victim = $this->victim(['password_reset_token' => $stored]);

        $body = ['email' => $victim->email, 'password' => 'New-Password1', 'password_confirmation' => 'New-Password1'];

        $this->postJson('/api/en/reset-password', $body + ['token' => $stored])->assertStatus(400);
        $this->assertTrue(Hash::check('Old-Password1', $victim->refresh()->password));

        $this->postJson('/api/en/reset-password', $body + ['token' => $plain])->assertOk();
        $this->assertTrue(Hash::check('New-Password1', $victim->refresh()->password));
    }

    public function test_forgot_password_stores_only_a_hash_of_the_token(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $victim = $this->victim();

        $this->postJson('/api/en/forgot-password', ['email' => $victim->email])->assertOk();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $victim->refresh()->password_reset_token);
    }
}
