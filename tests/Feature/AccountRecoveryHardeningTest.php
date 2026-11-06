<?php

namespace Tests\Feature;

use App\ApiKey;
use App\ApiWebhook;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions from security audit run-4.
 *
 * users.email is utf8mb4_unicode_ci, so MySQL matches victim@exämple.test to
 * victim@example.test. The recovery flows used to mail the secret to the address in
 * the request, which handed any account to whoever owned a look-alike domain. These
 * tests run against real MySQL, so the collation in them is the real one.
 */
class AccountRecoveryHardeningTest extends TestCase
{
    private const PASSWORD = 'Original-Passw0rd';

    /** @var array<int, array{to: string[], body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'app.front_url' => 'https://front.test']);
        Event::listen(MessageSent::class, function (MessageSent $event) {
            $this->mails[] = [
                'to' => array_map(fn ($a) => $a->getAddress(), $event->message->getTo()),
                'body' => quoted_printable_decode((string) $event->message->getHtmlBody()),
            ];
        });
    }

    private function user(array $overrides = []): User
    {
        $local = 'victim' . Str::lower(Str::random(10));

        return User::create(array_merge([
            'username' => $local,
            'email' => $local . '@example.test',
            'password' => bcrypt(self::PASSWORD),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ], $overrides));
    }

    private function lookAlike(User $user): string
    {
        return str_replace('@example.test', '@exämple.test', $user->email);
    }

    /** A new client IP per call, so these tests measure the email bucket on its own. */
    private function fromNewIp(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.' . random_int(1, 250) . '.' . random_int(1, 250)]);
    }

    private function resetTokenFromMail(int $index = 0): string
    {
        preg_match('~/reset-password/[^/"\s]+/([^"\s<]+)~', $this->mails[$index]['body'] ?? '', $m);
        $this->assertNotEmpty($m[1] ?? null, 'the mail carries a reset link');

        return html_entity_decode($m[1]);
    }

    private function reset(string $email, string $token, string $password = 'Brand-New-Passw0rd')
    {
        return $this->fromNewIp()->postJson('/api/en/reset-password', [
            'email' => $email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    // --- Look-alike addresses -------------------------------------------------------

    public function test_the_database_really_does_match_the_look_alike(): void
    {
        $user = $this->user();

        // If this ever fails the collation changed and the tests below prove nothing.
        $this->assertSame($user->id, User::where('email', $this->lookAlike($user))->value('id'));
    }

    public function test_forgot_password_for_a_look_alike_sends_nothing_and_stores_no_token(): void
    {
        $user = $this->user();

        $response = $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $this->lookAlike($user)]);

        $response->assertOk();
        $this->assertSame([], $this->mails);
        $this->assertNull($user->fresh()->password_reset_token);
    }

    public function test_forgot_password_mails_the_stored_address(): void
    {
        $user = $this->user();

        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => strtoupper($user->email)])->assertOk();

        $this->assertCount(1, $this->mails);
        $this->assertSame([$user->email], $this->mails[0]['to']);
    }

    public function test_reset_password_refuses_a_look_alike_even_with_the_right_token(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetTokenFromMail();

        $this->reset($this->lookAlike($user), $token)->assertStatus(400);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_resend_for_a_look_alike_sends_nothing(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 123456]);

        $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $this->lookAlike($user)])->assertOk();

        $this->assertSame([], $this->mails);
        $this->assertEquals(123456, $user->fresh()->account_verification_code);
    }

    public function test_verify_email_refuses_a_look_alike_even_with_the_right_token_and_code(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 123456]);
        Cache::put('verify-email:issued:' . sha1($user->email), now(), now()->addHour());

        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $this->lookAlike($user), 'token' => 'link-token', 'code' => '123456',
        ])->assertStatus(400);

        $this->assertEquals(0, $user->fresh()->email_verified);
    }

    public function test_login_refuses_a_look_alike_even_with_the_right_password(): void
    {
        $user = $this->user();

        $this->fromNewIp()->postJson('/api/en/login', ['email' => $this->lookAlike($user), 'password' => self::PASSWORD])
            ->assertUnauthorized();
        $this->fromNewIp()->postJson('/api/en/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_google_sign_in_does_not_link_a_look_alike_address(): void
    {
        $user = $this->user();
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($user) {
            $mock->shouldReceive('verify')->andReturn([
                'sub' => 'google-sub-attacker', 'email' => $this->lookAlike($user), 'name' => 'Attacker',
            ]);
        });

        $this->fromNewIp()->postJson('/api/auth/google-sync', ['id_token' => 'stubbed'])
            ->assertStatus(409)
            ->assertJsonMissingPath('token');

        $this->assertNull($user->fresh()->google_id);
    }

    // --- Expiry ---------------------------------------------------------------------

    public function test_a_reset_link_works_inside_its_window_even_if_the_cache_was_cleared(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetTokenFromMail();

        Cache::flush();

        $this->reset($user->email, $token)->assertOk();
    }

    public function test_a_reset_link_stops_working_after_its_window(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetTokenFromMail();

        $this->travel(61)->minutes();

        $this->reset($user->email, $token)->assertStatus(400);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_reset_token_without_an_embedded_expiry_is_refused(): void
    {
        $legacy = bin2hex(random_bytes(16));
        $user = $this->user(['password_reset_token' => hash('sha256', $legacy)]);

        $this->reset($user->email, $legacy)->assertStatus(400);
    }

    public function test_the_expiry_in_a_reset_token_cannot_be_extended(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        [, $secret] = explode('-', $this->resetTokenFromMail(), 2);

        $this->reset($user->email, (now()->addYear()->timestamp) . '-' . $secret)->assertStatus(400);
    }

    public function test_a_verification_code_with_no_issue_marker_is_refused(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 123456]);

        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $user->email, 'token' => 'link-token', 'code' => '123456',
        ])->assertStatus(400);
    }

    public function test_a_verification_code_stops_working_after_its_window(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 111111]);
        $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $user->email])->assertOk();
        $code = (string) $user->fresh()->account_verification_code;

        $this->travel(31)->minutes();

        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $user->email, 'token' => 'link-token', 'code' => $code,
        ])->assertStatus(400);

        // Resend is the way back in.
        $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $user->email])->assertOk();
        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $user->email, 'token' => 'link-token', 'code' => (string) $user->fresh()->account_verification_code,
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();
    }

    // --- Revocation -----------------------------------------------------------------

    private function changePassword(User $user, string $bearer)
    {
        return $this->fromNewIp()->withToken($bearer)->putJson('/api/en/change-password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'Changed-Passw0rd',
            'new_password_confirmation' => 'Changed-Passw0rd',
        ]);
    }

    public function test_changing_the_password_cancels_an_outstanding_reset_link(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetTokenFromMail();

        $this->changePassword($user, $user->createToken('t')->plainTextToken)->assertOk();

        $this->assertNull($user->fresh()->password_reset_token);
        $this->reset($user->email, $token)->assertStatus(400);
    }

    private function resellerWithKeyAndWebhook(): array
    {
        $user = $this->user();
        $user->forceFill(['api_enabled' => 1])->save();
        [, $plain] = ApiKey::issue($user, 'integration');
        ApiWebhook::create(['user_id' => $user->id, 'url' => 'https://reseller.example/hook', 'secret' => 'whsec_x']);

        return [$user, $plain];
    }

    public function test_changing_the_password_revokes_api_keys_and_the_webhook_and_tells_the_owner(): void
    {
        [$user, $plain] = $this->resellerWithKeyAndWebhook();
        $this->fromNewIp()->withToken($plain)->getJson('/api/v1/balance')->assertOk();
        $session = $user->createToken('t')->plainTextToken;

        // Guards keep their user between requests in a test; a real request starts clean.
        $this->app['auth']->forgetGuards();
        $this->changePassword($user, $session)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->fromNewIp()->withToken($plain)->getJson('/api/v1/balance')->assertUnauthorized();
        $this->assertFalse(ApiWebhook::where('user_id', $user->id)->exists());
        $this->assertCount(1, $this->mails);
        $this->assertSame([$user->email], $this->mails[0]['to']);
        $this->assertStringContainsString(__('emails.password_changed.api_access_revoked'), $this->mails[0]['body']);

        // The device that made the change stays signed in.
        $this->fromNewIp()->withToken($session)->getJson('/api/en/user/profile')->assertOk();
    }

    public function test_resetting_the_password_revokes_api_keys_and_the_webhook(): void
    {
        [$user, $plain] = $this->resellerWithKeyAndWebhook();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();

        $this->reset($user->email, $this->resetTokenFromMail())->assertOk();

        $this->app['auth']->forgetGuards();
        $this->fromNewIp()->withToken($plain)->getJson('/api/v1/balance')->assertUnauthorized();
        $this->assertFalse(ApiWebhook::where('user_id', $user->id)->exists());
    }

    // --- Lockout --------------------------------------------------------------------

    public function test_strangers_cannot_lock_an_owner_out_of_login(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->fromNewIp()->postJson('/api/en/login', ['email' => $user->email, 'password' => 'Wrong-Passw0rd'])
                ->assertUnauthorized();
            $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email]);
        }

        $this->fromNewIp()->postJson('/api/en/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_one_ip_is_still_limited_on_login(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.18.' . random_int(1, 250) . '.' . random_int(1, 250)]);
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->postJson('/api/en/login', ['email' => 'a' . $i . uniqid() . '@example.test', 'password' => 'x'])->getStatusCode();
        }

        $this->assertSame(429, $codes[5]);
    }

    public function test_a_repeat_forgot_password_leaves_the_emailed_link_working(): void
    {
        $user = $this->user();
        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->resetTokenFromMail();

        $this->fromNewIp()->postJson('/api/en/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertCount(1, $this->mails, 'a stranger cannot flood the inbox');
        $this->reset($user->email, $token)->assertOk();
    }

    public function test_wrong_tokens_from_a_stranger_do_not_lock_the_real_registrant(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 111111]);
        $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $user->email])->assertOk();

        for ($i = 0; $i < 6; $i++) {
            $this->fromNewIp()->postJson('/api/verify-email', [
                'email' => $user->email, 'token' => 'wrong-token', 'code' => '000000',
            ])->assertStatus(400);
        }

        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $user->email, 'token' => 'link-token', 'code' => (string) $user->fresh()->account_verification_code,
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();
    }

    public function test_the_daily_code_guess_budget_survives_a_resend(): void
    {
        $user = $this->user(['email_verified' => 0, 'verification_token' => 'link-token', 'account_verification_code' => 111111]);
        $codes = [];

        // Four rounds of five wrong guesses, each followed by a resend that resets the
        // per-code counter, uses the whole daily budget of twenty.
        for ($round = 0; $round < 4; $round++) {
            $this->travel(2)->minutes(); // past the per-minute limit on resends to one address
            $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $user->email])->assertOk();
            for ($i = 0; $i < 5; $i++) {
                $wrong = $user->fresh()->account_verification_code == 999999 ? '999998' : '999999';
                $this->fromNewIp()->postJson('/api/verify-email', [
                    'email' => $user->email, 'token' => 'link-token', 'code' => $wrong,
                ])->assertStatus(400);
            }
        }

        $this->travel(2)->minutes();
        $this->fromNewIp()->postJson('/api/resend-verification-code', ['email' => $user->email])->assertOk();
        $this->fromNewIp()->postJson('/api/verify-email', [
            'email' => $user->email, 'token' => 'link-token', 'code' => (string) $user->fresh()->account_verification_code,
        ])->assertStatus(429);
    }
}
