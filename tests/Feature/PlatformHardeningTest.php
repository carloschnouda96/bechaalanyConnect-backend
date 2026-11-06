<?php

namespace Tests\Feature;

use App\ApiKey;
use App\ApiWebhook;
use App\ContactFormRequest;
use App\Models\User;
use App\Order;
use App\Services\Reseller\WebhookUrlGuard;
use App\Services\Reseller\WebhookUrlRejected;
use App\Services\Cms\CatalogCsv;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regressions from security audit run-4: public endpoints, API keys, webhooks. */
class PlatformHardeningTest extends TestCase
{
    use CreatesCatalog;

    private const PASSWORD = 'Original-Passw0rd';

    private function user(array $overrides = []): User
    {
        $local = 'plat' . Str::lower(Str::random(10));

        return User::create($overrides + [
            'username' => $local,
            'email' => $local . '@example.test',
            'password' => bcrypt(self::PASSWORD),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function fromNewIp(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.' . random_int(1, 250) . '.' . random_int(1, 250)]);
    }

    private function registration(string $email, string $username): array
    {
        return [
            'username' => $username,
            'email' => $email,
            'password' => 'Attacker123',
            'password_confirmation' => 'Attacker123',
        ];
    }

    // --- Registration ------------------------------------------------------------------

    public function test_registration_answers_the_same_for_a_taken_and_a_free_address(): void
    {
        Mail::fake();
        $taken = $this->user();

        $known = $this->fromNewIp()->postJson('/api/register', $this->registration($taken->email, 'known' . uniqid()));
        $free = $this->fromNewIp()->postJson('/api/register', $this->registration('free' . uniqid() . '@example.test', 'free' . uniqid()));

        $this->assertSame(201, $known->status());
        $this->assertSame(201, $free->status());
        $this->assertSame(array_keys($free->json()), array_keys($known->json()));
        $this->assertSame($free->json('message'), $known->json('message'));
        $this->assertSame(1, User::where('email', $taken->email)->count());
        $this->assertTrue(Hash::check(self::PASSWORD, $taken->fresh()->password));
    }

    public function test_a_taken_address_gets_a_notice_at_the_stored_address_only(): void
    {
        config(['mail.default' => 'array']);
        $to = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$to) {
            foreach ($event->message->getTo() as $address) {
                $to[] = $address->getAddress();
            }
        });
        $taken = $this->user();

        $this->fromNewIp()->postJson('/api/register', $this->registration($taken->email, 'look' . uniqid()))->assertCreated();

        $this->assertSame([$taken->email], $to);
    }

    // --- API keys and webhook ---------------------------------------------------------

    private function reseller(): array
    {
        $user = $this->user();
        $user->forceFill(['api_enabled' => 1])->save();

        return [$user, $user->createToken('t')->plainTextToken];
    }

    public function test_creating_an_api_key_needs_the_password(): void
    {
        [$user, $token] = $this->reseller();

        $this->fromNewIp()->withToken($token)->postJson('/api/user/api-keys', ['name' => 'stolen session'])->assertStatus(422);
        $this->fromNewIp()->withToken($token)->postJson('/api/user/api-keys', ['name' => 'x', 'current_password' => 'wrong'])->assertStatus(422);
        $this->assertSame(0, ApiKey::where('user_id', $user->id)->count());

        $this->fromNewIp()->withToken($token)->postJson('/api/user/api-keys', ['name' => 'ok', 'current_password' => self::PASSWORD])->assertCreated();
        $this->assertSame(1, ApiKey::where('user_id', $user->id)->count());
    }

    public function test_changing_the_webhook_url_needs_the_password(): void
    {
        [$user, $token] = $this->reseller();
        $this->app->bind(WebhookUrlGuard::class, fn () => new WebhookUrlGuard(fn () => ['93.184.216.34']));

        $this->fromNewIp()->withToken($token)->putJson('/api/user/api-webhook', ['url' => 'https://hook.example/x'])->assertStatus(422);
        $this->assertFalse(ApiWebhook::where('user_id', $user->id)->exists());

        $this->fromNewIp()->withToken($token)->putJson('/api/user/api-webhook', [
            'url' => 'https://hook.example/x', 'current_password' => self::PASSWORD,
        ])->assertCreated();
    }

    public function test_rotating_the_webhook_secret_needs_the_password(): void
    {
        [$user, $token] = $this->reseller();
        ApiWebhook::create(['user_id' => $user->id, 'url' => 'https://hook.example/x', 'secret' => 'whsec_old']);

        $this->fromNewIp()->withToken($token)->postJson('/api/user/api-webhook/rotate-secret')->assertStatus(422);
        $this->assertSame('whsec_old', ApiWebhook::where('user_id', $user->id)->first()->secret);
    }

    public function test_the_webhook_guard_refuses_non_standard_ports(): void
    {
        $guard = new WebhookUrlGuard(fn () => ['93.184.216.34']);

        $this->assertSame(443, $guard->resolve('https://hook.example/x')['port']);
        $this->assertSame(443, $guard->resolve('https://hook.example:443/x')['port']);

        $this->expectException(WebhookUrlRejected::class);
        $guard->resolve('https://hook.example:8443/x');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('embeddedAddresses')]
    public function test_the_webhook_guard_refuses_ipv6_forms_that_embed_a_private_address(string $ip): void
    {
        $this->assertFalse(WebhookUrlGuard::isPublicIp($ip), $ip);
    }

    public static function embeddedAddresses(): array
    {
        return [
            'v4-compatible loopback' => ['::7f00:1'],
            'v4-compatible private' => ['::a00:1'],
            '6to4 loopback' => ['2002:7f00:1::'],
            '6to4 private' => ['2002:a00:1::1'],
            'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'local nat64' => ['64:ff9b:1::7f00:1'],
            'ipv4-translated' => ['::ffff:0:7f00:1'],
        ];
    }

    public function test_the_webhook_guard_still_accepts_a_public_ipv6_address(): void
    {
        $this->assertTrue(WebhookUrlGuard::isPublicIp('2606:4700:4700::1111'));
    }

    // --- Limits -----------------------------------------------------------------------

    public function test_the_locale_segment_only_accepts_real_locales(): void
    {
        $this->fromNewIp()->getJson('/api/v1/credit-types?locale=en')->assertNotFound();
        $this->fromNewIp()->getJson('/api/en/credit-types')->assertSuccessful();
    }

    public function test_one_ip_cannot_try_reseller_keys_without_limit(): void
    {
        $ip = '198.18.' . random_int(1, 250) . '.' . random_int(1, 250);
        $last = null;

        for ($i = 0; $i < 601; $i++) {
            $last = $this->withServerVariables(['REMOTE_ADDR' => $ip])->withToken('bad-' . $i)->getJson('/api/v1/balance')->status();
            if ($last === 429) {
                break;
            }
        }

        $this->assertSame(429, $last);
    }

    public function test_order_lists_cap_the_page_size(): void
    {
        $user = $this->user();
        $token = $user->createToken('t')->plainTextToken;
        $variation = $this->createVariation(5);
        foreach (range(1, 3) as $i) {
            Order::create(['users_id' => $user->id, 'product_variation_id' => $variation->id, 'quantity' => 1, 'total_price' => 5, 'statuses_id' => Order::STATUS_PENDING]);
        }

        $this->fromNewIp()->withToken($token)->getJson('/api/en/user/orders?limit=-1')->assertOk();
        $this->fromNewIp()->withToken($token)->getJson('/api/en/user/orders?limit=abc')->assertOk();
        $big = $this->fromNewIp()->withToken($token)->getJson('/api/en/user/orders?limit=100000');
        $big->assertOk();
    }

    public function test_the_profile_returns_a_bounded_number_of_orders(): void
    {
        $user = $this->user();
        $token = $user->createToken('t')->plainTextToken;
        $variation = $this->createVariation(5);
        $rows = array_map(fn () => [
            'users_id' => $user->id, 'product_variation_id' => $variation->id, 'quantity' => 1,
            'total_price' => 5, 'statuses_id' => Order::STATUS_PENDING, 'created_at' => now(),
        ], range(1, 105));
        Order::insert($rows);

        $response = $this->fromNewIp()->withToken($token)->getJson('/api/en/user/profile');

        $response->assertOk();
        $this->assertCount(100, $response->json('orders'));
    }

    // --- Serialization ----------------------------------------------------------------

    public function test_customer_order_json_hides_supplier_bookkeeping(): void
    {
        $user = $this->user();
        $token = $user->createToken('t')->plainTextToken;
        $order = Order::create([
            'users_id' => $user->id, 'product_variation_id' => $this->createVariation(5)->id, 'quantity' => 1, 'total_price' => 5,
            'statuses_id' => Order::STATUS_APPROVED, 'external_source' => 'usharez',
            'external_order_id' => 'usharez-uuid', 'external_status' => 'completed',
        ]);

        foreach (["/api/en/user/orders/{$order->id}", '/api/en/user/orders', '/api/en/user/profile'] as $url) {
            $body = $this->fromNewIp()->withToken($token)->getJson($url)->assertOk()->getContent();
            foreach (['external_source', 'external_order_id', 'external_status', 'usharez'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$secret} on {$url}");
            }
        }
    }

    // --- Contact form -----------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('badContactForms')]
    public function test_the_contact_form_refuses_oversized_or_non_text_input(array $overrides): void
    {
        Mail::fake();
        $before = ContactFormRequest::count();

        $this->fromNewIp()->postJson('/api/contact-form-submit', $overrides + [
            'name' => 'A', 'email' => 'a@example.test', 'phone' => '123', 'subject' => 'Hello', 'message' => 'Hi',
        ])->assertStatus(422);

        $this->assertSame($before, ContactFormRequest::count());
    }

    public static function badContactForms(): array
    {
        return [
            'long message' => [['message' => str_repeat('x', 5001)]],
            'array message' => [['message' => ['a']]],
            'long subject' => [['subject' => str_repeat('x', 192)]],
            'long phone' => [['phone' => str_repeat('1', 33)]],
        ];
    }

    // --- CSV --------------------------------------------------------------------------

    public function test_exported_text_cannot_start_a_spreadsheet_formula(): void
    {
        $this->assertSame("'=HYPERLINK(1)", CatalogCsv::neutralise('=HYPERLINK(1)'));
        $this->assertSame("'+1", CatalogCsv::neutralise('+1'));
        $this->assertSame("'-1", CatalogCsv::neutralise('-1'));
        $this->assertSame("'@x", CatalogCsv::neutralise('@x'));
        $this->assertSame('Plain name', CatalogCsv::neutralise('Plain name'));
        $this->assertSame('=HYPERLINK(1)', CatalogCsv::restore("'=HYPERLINK(1)"));
        $this->assertSame("'quoted", CatalogCsv::restore("'quoted"));
    }
}
