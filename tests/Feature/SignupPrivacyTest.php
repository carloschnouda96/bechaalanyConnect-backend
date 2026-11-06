<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions from security audit run-5.
 *
 * A sign-up writes no users row until the address is proven. A row used to exist from
 * the first POST, which is what let a stranger pre-set the password of someone else's
 * address, reserve usernames (an oracle for whether that row was created), and squat
 * an accented look-alike.
 */
class SignupPrivacyTest extends TestCase
{
    private const PASSWORD = 'Registrant-Passw0rd';

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

    private function address(): string
    {
        return 'signup' . Str::lower(Str::random(10)) . '@example.test';
    }

    private function register(string $email, array $overrides = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.' . random_int(1, 250) . '.' . random_int(1, 250)])
            ->postJson('/api/register', array_merge([
                'username' => 'u' . Str::lower(Str::random(10)),
                'email' => $email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ], $overrides));
    }

    private function verify(array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.2.' . random_int(1, 250) . '.' . random_int(1, 250)])
            ->postJson('/api/verify-email', $payload);
    }

    private function lastCode(): string
    {
        preg_match('/<h1>(\d{6})<\/h1>/', end($this->mails)['body'], $m);

        return $m[1];
    }

    private function lastLinkToken(): string
    {
        preg_match('#/email-verification/[^/]+/([a-f0-9]{32})#', end($this->mails)['body'], $m);

        return $m[1];
    }

    private function legacyUnverified(string $email): User
    {
        return User::create([
            'username' => 'l' . Str::lower(Str::random(10)),
            'email' => $email,
            'password' => bcrypt('Squatter-Passw0rd'),
            'email_verified' => 0,
            'verification_token' => bin2hex(random_bytes(16)),
            'account_verification_code' => 123456,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
    }

    public function test_registering_writes_no_account_until_the_address_is_proven(): void
    {
        $email = $this->address();

        $this->register($email)->assertStatus(201);

        $this->assertNull(User::withTrashed()->where('email', $email)->first());
    }

    public function test_the_signup_page_finishes_with_the_handle_and_keeps_the_chosen_password(): void
    {
        $email = $this->address();
        $handle = $this->register($email)->json('verification_token');

        $this->verify(['email' => $email, 'token' => $handle, 'code' => $this->lastCode()])
            ->assertOk()->assertJsonStructure(['token', 'user']);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue((bool) $user->email_verified);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_the_handle_returned_is_not_the_emailed_links_token(): void
    {
        $handle = $this->register($this->address())->json('verification_token');

        $this->assertNotSame($this->lastLinkToken(), $handle);
    }

    public function test_a_handle_for_another_address_does_not_verify(): void
    {
        $handle = $this->register($this->address())->json('verification_token');
        $other = $this->address();
        $this->register($other);

        $this->verify(['email' => $other, 'token' => $handle, 'code' => $this->lastCode()])->assertStatus(400);
    }

    public function test_the_emailed_link_does_not_activate_a_password_someone_else_may_have_chosen(): void
    {
        $email = $this->address();
        $this->register($email, ['password' => 'Attacker-Passw0rd', 'password_confirmation' => 'Attacker-Passw0rd']);
        $payload = ['email' => $email, 'token' => $this->lastLinkToken(), 'code' => $this->lastCode()];

        $this->verify($payload)->assertStatus(422)->assertJsonPath('code', 'password_required');
        $this->assertNull(User::where('email', $email)->first());

        $this->verify($payload + ['password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9'])->assertOk();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check('Owner-Passw0rd9', $user->password));
        $this->assertFalse(Hash::check('Attacker-Passw0rd', $user->password));
    }

    public function test_an_existing_unverified_row_gets_the_same_treatment_on_its_emailed_link(): void
    {
        $email = $this->address();
        $user = $this->legacyUnverified($email);
        Cache::put('verify-email:issued:' . sha1($email), now(), now()->addHour());
        $payload = ['email' => $email, 'token' => $user->verification_token, 'code' => '123456'];

        $this->verify($payload)->assertStatus(422)->assertJsonPath('code', 'password_required');
        $this->verify($payload + ['password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9'])->assertOk();

        $this->assertTrue(Hash::check('Owner-Passw0rd9', $user->fresh()->password));
    }

    public function test_a_password_reset_proves_the_inbox_and_ends_an_unverified_row(): void
    {
        $email = $this->address();
        $user = $this->legacyUnverified($email);

        $this->postJson('/api/en/forgot-password', ['email' => $email])->assertOk();
        preg_match('#/reset-password/[^/]+/([0-9]+-[a-f0-9]+)#', end($this->mails)['body'], $m);

        $this->postJson('/api/en/reset-password', [
            'email' => $email, 'token' => $m[1], 'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->email_verified);
        $this->assertNull($fresh->verification_token);
        $this->assertNull($fresh->account_verification_code);
    }

    public function test_login_answers_alike_for_an_unverified_row_and_an_unknown_address(): void
    {
        $email = $this->address();
        $this->legacyUnverified($email);

        $unverified = $this->postJson('/api/en/login', ['email' => $email, 'password' => 'Squatter-Passw0rd']);
        $unknown = $this->postJson('/api/en/login', ['email' => $this->address(), 'password' => 'Squatter-Passw0rd']);

        $unverified->assertStatus(401);
        $unknown->assertStatus(401);
        $this->assertSame($unknown->json(), $unverified->json());
    }

    public function test_a_login_for_an_unknown_address_still_pays_for_a_password_hash(): void
    {
        Cache::put(\App\Http\Controllers\SessionController::dummyHashKey(), bcrypt('x'), 60);
        Hash::spy();

        $this->postJson('/api/en/login', ['email' => $this->address(), 'password' => 'Whatever-Passw0rd'])->assertStatus(401);

        // One check, as a real account pays. A hash made here would cost a second bcrypt.
        Hash::shouldHaveReceived('check')->once();
        Hash::shouldNotHaveReceived('make');
    }

    public function test_a_pending_signup_reserves_no_username(): void
    {
        $username = 'u' . Str::lower(Str::random(10));

        $this->register($this->address(), ['username' => $username])->assertStatus(201);
        $this->register($this->address(), ['username' => $username])->assertStatus(201);
    }

    public function test_wrong_codes_answer_alike_for_a_new_and_a_taken_address(): void
    {
        $taken = $this->address();
        User::create([
            'username' => 't' . Str::lower(Str::random(10)), 'email' => $taken, 'password' => bcrypt('x'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
        $fresh = $this->address();

        foreach ([$taken, $fresh] as $email) {
            $handle = $this->register($email)->json('verification_token');
            $statuses = [];
            for ($i = 0; $i < 6; $i++) {
                $statuses[] = $this->verify(['email' => $email, 'token' => $handle, 'code' => '000000'])->status();
            }
            $this->assertSame([400, 400, 400, 400, 400, 429], $statuses, $email);

            Cache::forget('verify-email:mail-cooldown:' . sha1($email));
            $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.' . random_int(1, 250)])
                ->postJson('/api/resend-verification-code', ['email' => $email])->assertOk();
            $this->verify(['email' => $email, 'token' => $handle, 'code' => '000000'])->assertStatus(400);
        }
    }

    public function test_a_handle_from_a_taken_address_still_verifies_nothing(): void
    {
        $email = $this->address();
        $user = $this->legacyUnverified($email);
        Cache::put('verify-email:issued:' . sha1($email), now(), now()->addHour());

        $handle = $this->register($email)->json('verification_token');

        $this->verify(['email' => $email, 'token' => $handle, 'code' => '123456'])->assertStatus(400);
        $this->assertFalse((bool) $user->fresh()->email_verified);
    }

    public function test_an_address_outside_plain_ascii_is_refused_at_signup(): void
    {
        $this->register('victim@ex' . "\u{00E4}" . 'mple.test')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_an_old_unverified_look_alike_does_not_block_the_real_address(): void
    {
        $real = $this->address();
        $lookAlike = str_replace('example', 'ex' . "\u{00E4}" . 'mple', $real);
        $squat = $this->legacyUnverified($lookAlike);

        $handle = $this->register($real)->json('verification_token');
        $this->assertSame([$real], end($this->mails)['to']);

        $this->verify(['email' => $real, 'token' => $handle, 'code' => $this->lastCode()])->assertOk();
        $this->assertNull(User::withTrashed()->find($squat->id));
    }

    public function test_google_does_not_create_an_account_on_a_non_ascii_address(): void
    {
        $email = 'victim' . Str::lower(Str::random(6)) . '@ex' . "\u{00E4}" . 'mple.test';
        $this->mock(GoogleIdTokenVerifier::class, fn ($m) => $m->shouldReceive('verify')->andReturn([
            'sub' => 'g' . Str::random(10), 'email' => $email, 'name' => 'Someone',
        ]));

        $this->postJson('/api/auth/google-sync', ['id_token' => 'x'])->assertStatus(409)->assertJsonPath('code', 'email_conflict');
        $this->assertNull(User::where('email', $email)->first());
    }

    public function test_the_verification_mail_carries_no_text_chosen_by_the_requester(): void
    {
        $this->register($this->address(), ['username' => 'visit-evil-example']);

        $this->assertStringNotContainsString('visit-evil-example', end($this->mails)['body']);
    }

    public function test_one_inbox_gets_one_verification_mail_a_minute_however_it_is_asked_for(): void
    {
        $email = $this->address();

        $this->register($email)->assertStatus(201);
        $this->register($email)->assertStatus(201);
        $this->postJson('/api/resend-verification-code', ['email' => $email])->assertOk();

        $this->assertCount(1, $this->mails);
    }

    public function test_strangers_on_other_ips_cannot_spend_an_addresss_recovery_or_login(): void
    {
        $email = $this->address();

        for ($i = 1; $i <= 14; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.' . $i])
                ->postJson('/api/en/forgot-password', ['email' => $email])->assertOk();
        }

        for ($i = 1; $i <= 32; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.2.' . $i])
                ->postJson('/api/en/login', ['email' => $email, 'password' => 'Wrong-Passw0rd'])->assertStatus(401);
        }
    }

    public static function badUsernames(): array
    {
        return ['a sentence' => ['Your account is locked call us'], 'a link' => ['visit.evil.example'], 'a slash' => ['http://evil'], 'too long' => [str_repeat('a', 31)]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badUsernames')]
    public function test_a_username_cannot_carry_a_sentence_or_a_link(string $username): void
    {
        $this->register($this->address(), ['username' => $username])->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_the_account_exists_notice_greets_nobody_by_a_username(): void
    {
        $email = $this->address();
        User::create([
            'username' => 'CallThisNumberNow', 'email' => $email, 'password' => bcrypt('x'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);

        $this->register($email)->assertStatus(201);

        $this->assertStringNotContainsString('CallThisNumberNow', end($this->mails)['body']);
    }

    public function test_an_existing_username_with_a_space_can_still_save_its_profile_unchanged(): void
    {
        $user = User::create([
            'username' => 'From Google ' . Str::random(4), 'email' => $this->address(), 'password' => bcrypt('x'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->putJson('/api/en/user/update', ['username' => $user->username])->assertOk();
        $this->putJson('/api/en/user/update', ['username' => 'now with.a link'])->assertStatus(422);
    }

    public function test_the_global_signup_mail_cap_never_touches_an_owners_recovery(): void
    {
        config(['services.auth.mail_hourly_cap' => 1]);
        $owner = $this->address();
        User::create([
            'username' => 'o' . Str::lower(Str::random(10)), 'email' => $owner, 'password' => bcrypt('x'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
        \Illuminate\Support\Facades\RateLimiter::clear('verification-mail-global');

        $this->register($this->address())->assertStatus(201);
        $this->assertCount(1, $this->mails);

        // Over the cap: same answer, no mail.
        $this->register($this->address())->assertStatus(201);
        $this->assertCount(1, $this->mails);

        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.' . $i])
                ->postJson('/api/en/forgot-password', ['email' => $this->address()])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.8.1.1'])
            ->postJson('/api/en/forgot-password', ['email' => $owner])->assertOk();
        $this->assertCount(2, $this->mails);

        \Illuminate\Support\Facades\RateLimiter::clear('verification-mail-global');
    }

    public function test_a_password_typed_on_the_page_wins_even_with_a_strangers_handle(): void
    {
        $email = $this->address();
        $handle = $this->register($email, ['password' => 'Attacker-Passw0rd', 'password_confirmation' => 'Attacker-Passw0rd'])->json('verification_token');

        $this->verify([
            'email' => $email, 'token' => $handle, 'code' => $this->lastCode(),
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check('Owner-Passw0rd9', $user->password));
        $this->assertFalse(Hash::check('Attacker-Passw0rd', $user->password));
    }

    public function test_the_emailed_link_works_after_a_stranger_spends_the_guess_counters(): void
    {
        $email = $this->address();
        $this->register($email);
        $code = $this->lastCode();
        $link = $this->lastLinkToken();
        Cache::forget('verify-email:mail-cooldown:' . sha1($email));
        $decoy = $this->register($email)->json('verification_token');
        $code = $this->lastCode();
        $link = $this->lastLinkToken();

        for ($i = 0; $i < 6; $i++) {
            $this->verify(['email' => $email, 'token' => $decoy, 'code' => '000000']);
        }
        \Illuminate\Support\Facades\RateLimiter::increment('verify-email:daily:' . sha1($email), 3600, 25);

        $this->verify([
            'email' => $email, 'token' => $link, 'code' => $code,
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();
    }

    public function test_the_emailed_link_takes_nothing_from_the_form_a_stranger_may_have_filled(): void
    {
        $email = $this->address();
        $this->register($email, ['username' => 'stranger_chose_this', 'phone' => '+96170000000', 'country' => 'Nowhere']);

        $this->verify([
            'email' => $email, 'token' => $this->lastLinkToken(), 'code' => $this->lastCode(),
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNotSame('stranger_chose_this', $user->username);
        $this->assertNull($user->phone_number);
        $this->assertNull($user->country);
    }

    public function test_many_strangers_cannot_stop_the_owner_signing_in_from_their_own_address(): void
    {
        $email = $this->address();
        User::create([
            'username' => 'o' . Str::lower(Str::random(10)), 'email' => $email, 'password' => bcrypt('Owner-Passw0rd9'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);

        for ($i = 1; $i <= 310; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit('login-failures:' . sha1($email));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.7'])
            ->postJson('/api/en/login', ['email' => $email, 'password' => 'Owner-Passw0rd9'])->assertOk();
    }

    public function test_one_source_guessing_one_account_is_refused_even_with_the_right_password(): void
    {
        $email = $this->address();
        User::create([
            'username' => 'o' . Str::lower(Str::random(10)), 'email' => $email, 'password' => bcrypt('Owner-Passw0rd9'), 'email_verified' => 1,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);
        $ip = '10.7.8.' . random_int(1, 250);

        for ($i = 1; $i <= 30; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit(\App\Services\Auth\CredentialRevocation::loginFailureKey($email, $ip));
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/en/login', ['email' => $email, 'password' => 'Owner-Passw0rd9'])->assertStatus(429);
    }

    public function test_a_blank_typed_password_is_refused_rather_than_falling_back_to_the_forms(): void
    {
        $email = $this->address();
        $handle = $this->register($email, ['password' => 'Attacker-Passw0rd', 'password_confirmation' => 'Attacker-Passw0rd'])->json('verification_token');

        $this->verify([
            'email' => $email, 'token' => $handle, 'code' => $this->lastCode(),
            'password' => '        ', 'password_confirmation' => '        ',
        ])->assertStatus(422);

        $this->assertNull(User::where('email', $email)->first());
    }

    public function test_a_strangers_requests_do_not_invalidate_what_the_owner_was_mailed(): void
    {
        $email = $this->address();
        $this->register($email);
        $code = $this->lastCode();
        $link = $this->lastLinkToken();

        Cache::forget('verify-email:mail-cooldown:' . sha1($email));
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.1'])->postJson('/api/resend-verification-code', ['email' => $email])->assertOk();
        $this->assertSame($code, $this->lastCode());

        Cache::forget('verify-email:mail-cooldown:' . sha1($email));
        $this->register($email)->assertStatus(201);
        $this->assertSame($code, $this->lastCode());
        $this->assertSame($link, $this->lastLinkToken());

        $this->verify([
            'email' => $email, 'token' => $link, 'code' => $code,
            'password' => 'Owner-Passw0rd9', 'password_confirmation' => 'Owner-Passw0rd9',
        ])->assertOk();
    }

    public function test_a_later_signup_does_not_take_over_the_first_forms_handle(): void
    {
        $email = $this->address();
        $handle = $this->register($email, ['username' => 'first_owner_' . Str::lower(Str::random(5))])->json('verification_token');

        Cache::forget('verify-email:mail-cooldown:' . sha1($email));
        $this->register($email, ['password' => 'Attacker-Passw0rd', 'password_confirmation' => 'Attacker-Passw0rd']);

        $this->verify(['email' => $email, 'token' => $handle, 'code' => $this->lastCode()])->assertOk();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertStringStartsWith('first_owner_', $user->username);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_an_array_password_on_login_is_a_validation_error(): void
    {
        $this->postJson('/api/en/login', ['email' => $this->address(), 'password' => ['x']])->assertStatus(422);
    }

    public function test_an_unknown_address_gets_the_same_timebox_as_a_real_account(): void
    {
        $spy = $this->spy(\Illuminate\Support\Timebox::class);
        $spy->shouldReceive('call')->andReturnUsing(fn ($callback) => $callback(new \Illuminate\Support\Timebox()));

        $this->postJson('/api/en/login', ['email' => $this->address(), 'password' => 'Whatever-Passw0rd'])->assertStatus(401);

        $spy->shouldHaveReceived('call')->once()->withArgs(fn ($c, $micro) => $micro === (int) config('auth.timebox_duration', 200000));
    }
}
