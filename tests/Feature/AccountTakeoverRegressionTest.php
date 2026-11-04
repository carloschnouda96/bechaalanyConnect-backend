<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regressions from the 2026-11 security audit (run-1).
 *
 * 1. Account pre-hijacking: /register lets anyone create an unverified row for any
 *    email with a password they choose. google-sync used to adopt that row on the
 *    real owner's first Google sign-in, flip email_verified and keep the password,
 *    so the person who pre-registered could log straight into the victim's account.
 * 2. forgot-password threw on an undefined variable for every registered address,
 *    so no reset mail was ever sent and the 500 told callers which emails exist.
 */
class AccountTakeoverRegressionTest extends TestCase
{
    private function fakeGoogle(string $email, string $sub = 'google-sub-victim'): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($email, $sub) {
            $mock->shouldReceive('verify')->andReturn([
                'sub' => $sub,
                'email' => $email,
                'name' => 'Victim',
            ]);
        });
    }

    public function test_google_sign_in_discards_the_password_of_an_unverified_pre_registration(): void
    {
        Mail::fake();
        $email = 'victim_' . uniqid() . '@example.test';

        $this->postJson('/api/register', [
            'username' => 'attacker1',
            'email' => $email,
            'password' => 'Attacker123',
            'password_confirmation' => 'Attacker123',
        ])->assertCreated();

        $preRegistered = User::where('email', $email)->firstOrFail();
        $staleToken = $preRegistered->createToken('planted')->plainTextToken;

        $this->fakeGoogle($email);
        $this->postJson('/api/auth/google-sync', ['id_token' => 'stubbed'])->assertOk();

        $user = $preRegistered->fresh();
        $this->assertSame($preRegistered->id, $user->id, 'the existing row is still the one linked');
        $this->assertSame('google-sub-victim', $user->google_id);
        $this->assertEquals(1, $user->email_verified);
        $this->assertNull($user->verification_token);
        $this->assertNull($user->account_verification_code);

        $this->postJson('/api/en/login', ['email' => $email, 'password' => 'Attacker123'])
            ->assertUnauthorized();

        $this->assertFalse(
            $user->tokens()->where('token', hash('sha256', explode('|', $staleToken)[1]))->exists(),
            'tokens issued before the owner proved the email are revoked'
        );
    }

    public function test_google_sign_in_keeps_the_password_of_an_already_verified_account(): void
    {
        $email = 'owner_' . uniqid() . '@example.test';
        User::create([
            'username' => 'owner',
            'email' => $email,
            'password' => bcrypt('Owner-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);

        $this->fakeGoogle($email, 'google-sub-owner');
        $this->postJson('/api/auth/google-sync', ['id_token' => 'stubbed'])->assertOk();

        $this->postJson('/api/en/login', ['email' => $email, 'password' => 'Owner-Password1'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_forgot_password_sends_a_localised_link_and_answers_like_an_unknown_email(): void
    {
        Mail::fake();
        $email = 'reset_' . uniqid() . '@example.test';
        User::create([
            'username' => 'resetter',
            'email' => $email,
            'password' => bcrypt('Old-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_UNSUBMITTED,
        ]);

        $known = $this->postJson('/api/ar/forgot-password', ['email' => $email]);
        $unknown = $this->postJson('/api/ar/forgot-password', ['email' => 'nobody_' . uniqid() . '@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($unknown->json(), $known->json());

        $this->assertNotNull(User::where('email', $email)->value('password_reset_token'));
    }
}
