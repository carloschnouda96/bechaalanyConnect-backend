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

    public function test_many_sources_guessing_cannot_refuse_the_admins_correct_password(): void
    {
        $admin = $this->admin();
        \Illuminate\Support\Facades\Log::spy();

        for ($source = 1; $source <= 6; $source++) {
            for ($i = 0; $i < 10; $i++) {
                $this->withServerVariables(['REMOTE_ADDR' => '198.51.' . (100 + $source) . '.' . ($i + 1)])
                    ->post('/admin/login', ['email' => $admin->email, 'password' => 'Wrong-Passw0rd']);
            }
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('critical')->once();

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

    public function test_accented_spellings_share_the_real_admins_guess_budget(): void
    {
        $admin = $this->admin();
        $ip = '198.18.' . random_int(1, 250) . '.' . random_int(1, 250);
        $spelled = fn (int $i) => str_replace('session_', ['séssion_', 'sessión_', 'sessíon_', 'sèssion_', 'sêssion_'][$i % 5], $admin->email);
        $last = 0;

        for ($i = 0; $i < 11; $i++) {
            $this->travel(13)->seconds(); // stay under the per-minute limit, and test the address bucket
            $last = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/admin/login', ['email' => $spelled($i), 'password' => 'Wrong-Passw0rd'])->status();
        }

        $this->assertSame(429, $last);
    }

    public function test_a_login_email_that_is_not_a_string_is_refused(): void
    {
        $this->postJson('/admin/login', ['email' => false, 'password' => 'x'])->assertStatus(422);
        $this->post('/admin/login', ['email' => ['a@b.test'], 'password' => 'x'])->assertStatus(422);
    }

    public function test_changing_your_password_rotates_the_session_id(): void
    {
        $admin = $this->admin();
        Auth::guard('admin')->login($admin);
        $store = app('session.store');
        $before = $store->getId();

        $prefix = trim((string) config('hellotree.cms_route_prefix'), '/');
        $request = \Illuminate\Http\Request::create("/{$prefix}/profile/edit", 'POST', ['password' => 'Changed-Passw0rd']);
        $request->setLaravelSession($store);

        (new \App\Http\Middleware\BindAdminSessionToPassword())->handle($request, function () use ($admin) {
            Admin::where('id', $admin->id)->update(['password' => bcrypt('Changed-Passw0rd')]);

            return response('ok');
        });

        $this->assertNotSame($before, $store->getId(), 'a copied cookie must not survive the change');
    }
}
