<?php

namespace Tests\Feature\Reseller;

use App\ApiWebhook;
use App\Jobs\DeliverOrderWebhookJob;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\Services\Reseller\OrderPresenter;
use App\Services\Reseller\OrderWebhooks;
use App\Services\Reseller\WebhookSender;
use App\Services\Reseller\WebhookUrlGuard;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Reseller order-status webhooks.
 *
 * Pins: the SSRF guard (https only, never a private / loopback / reserved
 * address, checked again at delivery), that exactly one event is queued per
 * final transition of an API order — from an admin decision, a supplier
 * completion or a supplier refund — and never for storefront orders, and that a
 * delivery is signed so the documented verification recipe actually verifies.
 */
class ResellerWebhookTest extends TestCase
{
    use CreatesCatalog;

    private const PUBLIC_IP = '93.184.215.14';

    /** host => resolved addresses, for the fake DNS. */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(
            fn (string $host) => $this->dns[$host] ?? [self::PUBLIC_IP]
        ));
        Mail::fake();
    }

    private function reseller(bool $apiEnabled = true): User
    {
        $user = User::create([
            'username' => 'wh_' . uniqid(),
            'email' => 'wh_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
        $user->forceFill(['api_enabled' => $apiEnabled])->save();

        return $user;
    }

    private function webhookFor(User $user, string $url = 'https://shop.example.com/hooks'): ApiWebhook
    {
        return ApiWebhook::create(['user_id' => $user->id, 'url' => $url, 'secret' => 'whsec_test_secret']);
    }

    private function order(User $user, int $status, string $source = 'api', ?ProductsVariation $variation = null): Order
    {
        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => ($variation ?? $this->createVariation())->id,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
            'source' => $source,
            'client_order_id' => $source === 'api' ? 'SHOP-' . uniqid() : null,
        ]);
    }

    private function supplierVariation(): ProductsVariation
    {
        $variation = $this->createVariation();
        $variation->forceFill(['external_source' => 'yassen', 'external_id' => 'svc-' . uniqid()])->save();

        return $variation->refresh();
    }

    /** Stands in for Yassen: answers placeOrder / checkOrder with `$state->status`. */
    private function fakeSupplier(\stdClass $state): void
    {
        $this->app->instance(YassenConnector::class, new class($state) implements SupplierConnector {
            public function __construct(private \stdClass $state) {}
            public function key(): string { return 'yassen'; }
            public function isEnabled(): bool { return true; }
            public function isConfigured(): bool { return true; }
            public function fetchCatalog(): array { return []; }
            public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
            {
                return new SupplierOrderResult('ext-' . $order->id, $this->state->status);
            }
            public function checkOrder(Order $order): SupplierOrderResult
            {
                return new SupplierOrderResult($order->external_order_id, $this->state->status);
            }
            public function balance(): ?float { return null; }
        });
    }

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Webhook Admin';
        $admin->email = 'wh_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function bulk(array $ids, int $status): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/' . config('hellotree.cms_route_prefix') . '/orders/bulk-status', [
                'ids' => implode(',', $ids),
                'statuses_id' => $status,
            ])
            ->assertRedirect();
    }

    private function pushedFor(Order $order): \Illuminate\Support\Collection
    {
        return Queue::pushed(DeliverOrderWebhookJob::class, function (DeliverOrderWebhookJob $job) use ($order) {
            return json_decode($job->body, true)['data']['id'] === $order->id;
        });
    }

    // ── Endpoint settings ─────────────────────────────────────────────────────

    public function test_saving_a_webhook_returns_the_secret_once_and_stores_it_encrypted(): void
    {
        $user = $this->reseller();
        Sanctum::actingAs($user);

        $secret = $this->putJson('/api/user/api-webhook', ['url' => 'https://shop.example.com/hooks', 'current_password' => 'secret-Password1'])
            ->assertStatus(201)
            ->assertJsonPath('webhook.url', 'https://shop.example.com/hooks')
            ->json('secret');

        $this->assertStringStartsWith(ApiWebhook::SECRET_PREFIX, $secret);
        $raw = DB::table('api_webhooks')->where('user_id', $user->id)->value('secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, ApiWebhook::where('user_id', $user->id)->first()->secret);

        // Listing shows the URL, never the secret.
        $listed = $this->getJson('/api/user/api-keys')->assertOk()->json('webhook');
        $this->assertSame('https://shop.example.com/hooks', $listed['url']);
        $this->assertStringNotContainsString($secret, json_encode($listed));

        // Changing the URL keeps the secret.
        $this->putJson('/api/user/api-webhook', ['url' => 'https://shop.example.com/v2', 'current_password' => 'secret-Password1'])
            ->assertStatus(200)->assertJsonPath('secret', null);
        $this->assertSame($secret, ApiWebhook::where('user_id', $user->id)->first()->secret);

        $rotated = $this->postJson('/api/user/api-webhook/rotate-secret', ['current_password' => 'secret-Password1'])->assertOk()->json('secret');
        $this->assertNotSame($secret, $rotated);
        $this->assertSame($rotated, ApiWebhook::where('user_id', $user->id)->first()->secret);

        $this->deleteJson('/api/user/api-webhook')->assertOk();
        $this->assertNull($this->getJson('/api/user/api-keys')->json('webhook'));
    }

    public function test_unsafe_urls_are_refused(): void
    {
        Sanctum::actingAs($this->reseller());
        $this->dns['internal.example.com'] = ['192.168.1.10'];
        $this->dns['mixed.example.com'] = [self::PUBLIC_IP, '10.0.0.7'];
        $this->dns['nxdomain.example.com'] = [];

        foreach ([
            'http://shop.example.com/hooks',
            'https://user:pass@shop.example.com/hooks',
            'https://localhost/hooks',
            'https://127.0.0.1/hooks',
            'https://10.1.2.3/hooks',
            'https://169.254.169.254/latest/meta-data',
            'https://[::1]/hooks',
            'https://[::ffff:127.0.0.1]/hooks',
            'https://internal.example.com/hooks',
            'https://mixed.example.com/hooks',
            'https://nxdomain.example.com/hooks',
        ] as $url) {
            $this->putJson('/api/user/api-webhook', ['url' => $url, 'current_password' => 'secret-Password1'])
                ->assertStatus(422)->assertJsonValidationErrors('url');
        }

        $this->assertSame(0, ApiWebhook::count());
    }

    public function test_public_address_classification(): void
    {
        foreach (['8.8.8.8', self::PUBLIC_IP, '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(WebhookUrlGuard::isPublicIp($ip), $ip);
        }

        foreach (['127.0.0.1', '10.0.0.1', '172.20.1.1', '192.168.0.1', '100.64.0.1', '169.254.169.254',
                  '0.0.0.0', '224.0.0.1', '::1', '::', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1',
                  '64:ff9b::7f00:1', 'not-an-ip'] as $ip) {
            $this->assertFalse(WebhookUrlGuard::isPublicIp($ip), $ip);
        }
    }

    public function test_customer_without_api_access_cannot_set_a_webhook(): void
    {
        Sanctum::actingAs($this->reseller(false));

        $this->putJson('/api/user/api-webhook', ['url' => 'https://shop.example.com/hooks', 'current_password' => 'secret-Password1'])
            ->assertStatus(403)->assertJsonPath('code', 'api_not_enabled');
    }

    public function test_test_event_is_signed_and_reported(): void
    {
        $user = $this->reseller();
        $this->webhookFor($user);
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response('', 204)]);

        $this->postJson('/api/user/api-webhook/test')
            ->assertOk()->assertJsonPath('delivered', true)->assertJsonPath('status', 204);

        Http::assertSent(fn (HttpRequest $r) => $r->header('X-Bechaalany-Event')[0] === 'ping'
            && $this->signatureValid($r, 'whsec_test_secret'));
        $this->assertSame(204, ApiWebhook::where('user_id', $user->id)->first()->last_delivery_status);
    }

    // ── When events are queued ────────────────────────────────────────────────

    public function test_admin_approval_and_rejection_queue_one_event_each(): void
    {
        Queue::fake();
        $user = $this->reseller();
        $this->webhookFor($user);
        $approve = $this->order($user, Order::STATUS_PENDING);
        $reject = $this->order($user, Order::STATUS_PENDING);

        $this->bulk([$approve->id], Order::STATUS_APPROVED);
        $this->bulk([$reject->id], Order::STATUS_REJECTED);

        $this->assertCount(1, $this->pushedFor($approve));
        $this->assertCount(1, $this->pushedFor($reject));

        $job = $this->pushedFor($approve)->first();
        $payload = json_decode($job->body, true);
        $this->assertSame(OrderWebhooks::EVENT_COMPLETED, $job->eventType);
        $this->assertSame(OrderWebhooks::EVENT_COMPLETED, $payload['type']);
        $this->assertSame($job->eventId, $payload['id']);
        // The same shape GET /v1/orders/{id} returns.
        $this->assertSame(json_decode(json_encode(OrderPresenter::present($approve->fresh())), true), $payload['data']);
        $this->assertSame('completed', $payload['data']['status']);

        $this->assertSame(OrderWebhooks::EVENT_REJECTED, $this->pushedFor($reject)->first()->eventType);

        // A no-op save of a settled order queues nothing more.
        $this->bulk([$approve->id], Order::STATUS_APPROVED);
        $this->assertCount(1, $this->pushedFor($approve));
    }

    public function test_storefront_orders_and_users_without_a_webhook_or_access_get_nothing(): void
    {
        Queue::fake();
        $withHook = $this->reseller();
        $this->webhookFor($withHook);
        $storefront = $this->order($withHook, Order::STATUS_PENDING, 'storefront');
        $noHook = $this->order($this->reseller(), Order::STATUS_PENDING);
        $revoked = $this->reseller(false);
        $this->webhookFor($revoked);
        $noAccess = $this->order($revoked, Order::STATUS_PENDING);

        $this->bulk([$storefront->id, $noHook->id, $noAccess->id], Order::STATUS_APPROVED);

        Queue::assertNotPushed(DeliverOrderWebhookJob::class);
    }

    public function test_approving_a_supplier_order_waits_for_the_supplier(): void
    {
        Queue::fake();
        $user = $this->reseller();
        $this->webhookFor($user);
        $order = $this->order($user, Order::STATUS_PENDING, 'api', $this->supplierVariation());

        $this->bulk([$order->id], Order::STATUS_APPROVED);

        Queue::assertPushed(FulfillSupplierOrderJob::class);
        Queue::assertNotPushed(DeliverOrderWebhookJob::class);
    }

    public function test_supplier_completion_queues_completed_once(): void
    {
        Queue::fake();
        $state = (object) ['status' => SupplierOrderResult::PENDING];
        $this->fakeSupplier($state);
        $user = $this->reseller();
        $this->webhookFor($user);
        $order = $this->order($user, Order::STATUS_APPROVED, 'api', $this->supplierVariation());
        $fulfillment = app(SupplierOrderFulfillment::class);

        $fulfillment->fulfill($order);
        $this->assertCount(0, $this->pushedFor($order), 'still pending at the supplier');

        $state->status = SupplierOrderResult::COMPLETED;
        $fulfillment->refreshStatus($order->fresh());
        $fulfillment->refreshStatus($order->fresh());

        $this->assertCount(1, $this->pushedFor($order));
        $this->assertSame(OrderWebhooks::EVENT_COMPLETED, $this->pushedFor($order)->first()->eventType);
    }

    public function test_supplier_failure_refunds_and_queues_rejected(): void
    {
        Queue::fake();
        $this->fakeSupplier((object) ['status' => SupplierOrderResult::FAILED]);
        $user = $this->reseller();
        $this->webhookFor($user);
        $order = $this->order($user, Order::STATUS_APPROVED, 'api', $this->supplierVariation());

        app(SupplierOrderFulfillment::class)->fulfill($order);

        $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->statuses_id);
        $this->assertEquals(110, (float) $user->fresh()->credits_balance);
        $jobs = $this->pushedFor($order);
        $this->assertCount(1, $jobs);
        $this->assertSame(OrderWebhooks::EVENT_REJECTED, $jobs->first()->eventType);
        $this->assertSame('rejected', json_decode($jobs->first()->body, true)['data']['status']);
    }

    // ── Delivery ──────────────────────────────────────────────────────────────

    public function test_delivery_is_signed_and_recorded(): void
    {
        $user = $this->reseller();
        $this->webhookFor($user);
        Http::fake(['*' => Http::response('ok', 200)]);
        $body = '{"id":"evt_1","type":"order.completed","data":{"id":1}}';

        (new DeliverOrderWebhookJob($user->id, 'evt_1', 'order.completed', $body))->handle(app(WebhookSender::class));

        Http::assertSent(function (HttpRequest $r) use ($body) {
            return $r->url() === 'https://shop.example.com/hooks'
                && $r->method() === 'POST'
                && $r->body() === $body
                && $r->header('Content-Type')[0] === 'application/json'
                && $r->header('X-Bechaalany-Event-Id')[0] === 'evt_1'
                && $this->signatureValid($r, 'whsec_test_secret');
        });

        $webhook = ApiWebhook::where('user_id', $user->id)->first();
        $this->assertSame(200, $webhook->last_delivery_status);
        $this->assertNull($webhook->last_delivery_error);
        $this->assertNotNull($webhook->last_delivery_at);
    }

    public function test_a_failing_endpoint_is_retried_with_backoff(): void
    {
        $user = $this->reseller();
        $this->webhookFor($user);
        Http::fake(['*' => Http::response('boom', 500)]);
        $job = new DeliverOrderWebhookJob($user->id, 'evt_2', 'order.rejected', '{}');

        try {
            $job->handle(app(WebhookSender::class));
            $this->fail('A 500 must throw so the queue retries it.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $this->assertSame(500, ApiWebhook::where('user_id', $user->id)->first()->last_delivery_status);
        $this->assertSame(8, $job->tries);
        $this->assertCount($job->tries - 1, $job->backoff());
        $this->assertTrue($job->afterCommit);
    }

    public function test_a_connection_error_is_retried(): void
    {
        $user = $this->reseller();
        $this->webhookFor($user);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(ConnectionException::class);

        (new DeliverOrderWebhookJob($user->id, 'evt_3', 'order.completed', '{}'))->handle(app(WebhookSender::class));
    }

    public function test_a_url_rebound_to_a_private_address_is_never_requested(): void
    {
        $user = $this->reseller();
        $this->webhookFor($user, 'https://rebound.example.com/hooks');
        $this->dns['rebound.example.com'] = ['127.0.0.1'];
        Http::fake();

        // Not retried either: no exception escapes.
        (new DeliverOrderWebhookJob($user->id, 'evt_4', 'order.completed', '{}'))->handle(app(WebhookSender::class));

        Http::assertNothingSent();
        $this->assertStringContainsString('private', ApiWebhook::where('user_id', $user->id)->first()->last_delivery_error);
    }

    public function test_nothing_is_sent_once_the_webhook_or_api_access_is_gone(): void
    {
        Http::fake();
        $removed = $this->reseller();
        $disabled = $this->reseller();
        $this->webhookFor($disabled);
        $disabled->forceFill(['api_enabled' => false])->save();

        (new DeliverOrderWebhookJob($removed->id, 'evt_5', 'order.completed', '{}'))->handle(app(WebhookSender::class));
        (new DeliverOrderWebhookJob($disabled->id, 'evt_6', 'order.completed', '{}'))->handle(app(WebhookSender::class));

        Http::assertNothingSent();
    }

    /** The verification recipe from reseller-v1.yaml, done the way a reseller would. */
    private function signatureValid(HttpRequest $request, string $secret): bool
    {
        $header = $request->header('X-Bechaalany-Signature')[0] ?? '';
        parse_str(str_replace(',', '&', $header), $parts);

        if (!isset($parts['t'], $parts['v1']) || abs(time() - (int) $parts['t']) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $request->body(), $secret), $parts['v1']);
    }
}
