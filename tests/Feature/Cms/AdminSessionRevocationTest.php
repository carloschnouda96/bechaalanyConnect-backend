<?php

namespace Tests\Feature\Cms;

use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Revocation after a CMS password change used to rest on a cookie the client could
 * simply not send, and an admin-on-admin change did not trigger it at all. The session
 * is now bound on the server to the password it logged in with.
 */
class AdminSessionRevocationTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Session Admin';
        $admin->email = 'session_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->save();

        return $admin->refresh();
    }

    /** The session a real login would have left behind. */
    private function loggedInSession(Admin $admin): static
    {
        $guard = Auth::guard('admin');

        return $this->withSession([
            $guard->getName() => $admin->id,
            'password_hash_admin' => $guard->hashPasswordForCookie($admin->password),
        ]);
    }

    public function test_a_session_with_the_current_password_stays_logged_in(): void
    {
        $admin = $this->admin();

        $this->loggedInSession($admin)->get('/admin/profile')->assertOk();
    }

    public function test_a_password_change_ends_an_older_session_without_any_cookie(): void
    {
        $admin = $this->admin();
        $session = $this->loggedInSession($admin);

        // Set directly: this is what the Admins page does when another admin resets it,
        // which never touches reset_password_date.
        Admin::where('id', $admin->id)->update(['password' => bcrypt('Changed-Passw0rd')]);

        $session->get('/admin/profile')->assertRedirect(route('admin-login'));
    }

    public function test_a_real_session_with_no_password_binding_is_logged_out(): void
    {
        $admin = $this->admin();

        $this->withSession([Auth::guard('admin')->getName() => $admin->id])
            ->get('/admin/profile')
            ->assertRedirect(route('admin-login'));
    }

    public function test_changing_your_own_password_keeps_the_session_that_did_it(): void
    {
        $admin = $this->admin();

        $this->loggedInSession($admin)->post('/admin/profile/edit', [
            'name' => 'Session Admin',
            'password' => 'Changed-Passw0rd',
            'password_confirmation' => 'Changed-Passw0rd',
        ])->assertOk();

        $this->assertTrue(Hash::check('Changed-Passw0rd', $admin->fresh()->password));
        $this->get('/admin/profile')->assertOk();
    }

    public function test_failed_logins_from_other_ips_do_not_lock_out_the_real_admin(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 11; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.' . ($i + 1) ])
                ->post('/admin/login', ['email' => $admin->email, 'password' => 'Wrong-Passw0rd']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.' . random_int(1, 250)])
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'secret-Password1'])
            ->assertRedirect(route('admin-home'));
    }

    public function test_one_ip_guessing_one_admin_is_still_limited(): void
    {
        $admin = $this->admin();
        $ip = '198.18.' . random_int(1, 250) . '.' . random_int(1, 250);
        $codes = [];

        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/admin/login', ['email' => $admin->email, 'password' => 'Wrong-Passw0rd'])
                ->getStatusCode();
        }

        $this->assertSame(429, $codes[5]);
    }
}
