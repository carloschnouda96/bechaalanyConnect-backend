<?php

namespace Tests\Feature\Suppliers;

use App\Jobs\DeliverOrderWebhookJob;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\ProductPriceVariation;
use App\Services\PerfectPanel\PerfectPanelClient;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use App\Services\Swift\SwiftClient;
use App\Services\Yassen\YassenClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regressions from security audit run-4: supplier replies, keys, polling, queues. */
class SupplierHardeningTest extends TestCase
{
    use CreatesCatalog;

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.1xpanel.base_url' => 'https://1xpanel.test',
            'services.1xpanel.key' => 'SECRET-PANEL-KEY',
            'services.1xpanel.enabled' => true,
            'services.swift.base_url' => 'https://swift.test',
            'services.swift.key' => 'SECRET-SWIFT-KEY',
            'services.swift.enabled' => true,
        ]);
    }

    private function supplierOrder(string $source = '1xpanel', array $extra = []): Order
    {
        $variation = $this->createVariation(10);
        $variation->forceFill(['external_id' => '1', 'external_source' => $source])->save();

        $user = User::create([
            'username' => 'sup_' . uniqid(),
            'email' => 'sup_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create($extra + [
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'recipient_user' => 'https://example.test/me',
            'quantity' => 100,
            'total_price' => 10,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }

    private function fulfillment(): SupplierOrderFulfillment
    {
        return app(SupplierOrderFulfillment::class);
    }

    // --- Keys in error messages -------------------------------------------------------

    public function test_a_panel_read_failure_does_not_put_the_key_in_the_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error for https://1xpanel.test/api/v2?action=services&key=SECRET-PANEL-KEY'));

        try {
            (new PerfectPanelClient('https://1xpanel.test', 'SECRET-PANEL-KEY', '1xpanel'))->balance();
            $this->fail('expected a supplier error');
        } catch (SupplierApiException $e) {
            $this->assertStringNotContainsString('SECRET-PANEL-KEY', $e->getMessage());
        }
    }

    public function test_a_swift_read_failure_does_not_put_the_key_in_the_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error for https://swift.test/api/v2?action=services&key=SECRET-SWIFT-KEY'));

        try {
            (new SwiftClient('https://swift.test', 'SECRET-SWIFT-KEY'))->balance();
            $this->fail('expected a supplier error');
        } catch (SupplierApiException $e) {
            $this->assertStringNotContainsString('SECRET-SWIFT-KEY', $e->getMessage());
        }
    }

    // --- Replies that do not say what happened ----------------------------------------

    private function assertParkedAfterOnePost(Order $order): void
    {
        $fresh = $order->fresh();
        $this->assertSame(1, $this->posts);
        $this->assertStringStartsWith('parked-', (string) $fresh->external_order_id);
        $this->assertSame(SupplierOrderResult::PENDING, $fresh->external_status);

        // A retry of the job must not send it again.
        $this->fulfillment()->fulfill($fresh);
        $this->assertSame(1, $this->posts);
    }

    public function test_an_empty_reply_to_add_parks_the_order(): void
    {
        Http::fake(function () {
            $this->posts++;

            return Http::response('', 200);
        });
        $order = $this->supplierOrder();

        $this->fulfillment()->fulfill($order);

        $this->assertParkedAfterOnePost($order);
    }

    public function test_a_reply_with_no_order_id_parks_the_order(): void
    {
        Http::fake(function () {
            $this->posts++;

            return Http::response(['ok' => true], 200);
        });
        $order = $this->supplierOrder();

        $this->fulfillment()->fulfill($order);

        $this->assertParkedAfterOnePost($order);
    }

    public function test_swift_treats_a_reply_with_no_order_id_the_same_way(): void
    {
        Http::fake(function () {
            $this->posts++;

            return Http::response(['ok' => true], 200);
        });
        $order = $this->supplierOrder('swift');

        $this->fulfillment()->fulfill($order);

        $this->assertParkedAfterOnePost($order);
    }

    public function test_a_crash_after_sending_leaves_the_order_parked(): void
    {
        Http::fake(function () {
            $this->posts++;

            throw new \RuntimeException('worker died after the POST');
        });
        $order = $this->supplierOrder();

        try {
            $this->fulfillment()->fulfill($order);
        } catch (\RuntimeException) {
            // what a dead worker looks like here
        }

        $this->assertParkedAfterOnePost($order);
    }

    public function test_a_definite_refusal_stays_retryable(): void
    {
        Http::fake(function () {
            $this->posts++;

            return Http::response(['error' => 'Invalid key'], 401);
        });
        $order = $this->supplierOrder();

        try {
            $this->fulfillment()->fulfill($order);
            $this->fail('expected the job to see the error');
        } catch (SupplierApiException) {
            $this->assertNull($order->fresh()->external_order_id, 'nothing was bought, so a retry may send it');
        }
    }

    // --- Yassen -----------------------------------------------------------------------

    public function test_a_yassen_purchase_is_not_auto_retried_on_a_server_error(): void
    {
        Http::fake(function () {
            $this->posts++;

            return Http::response('', 502);
        });
        $connector = new YassenConnector(new YassenClient('https://yassen.test', 'token'));
        $order = $this->supplierOrder('yassen', ['external_order_uuid' => (string) Str::uuid()]);

        $result = $connector->placeOrder($order, ProductsVariation::find($order->product_variation_id));

        $this->assertSame(1, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertNotNull($result->externalOrderId, 'fulfill() must not send it again');
    }

    public function test_a_parked_yassen_order_resolves_by_its_uuid(): void
    {
        Http::fake(['*' => Http::response(['data' => [['order_id' => 9, 'status' => 'accept']]], 200)]);
        $connector = new YassenConnector(new YassenClient('https://yassen.test', 'token'));
        $order = $this->supplierOrder('yassen', ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => 'parked-x']);

        $this->assertSame(SupplierOrderResult::COMPLETED, $connector->checkOrder($order)->status);
    }

    // --- Polling ----------------------------------------------------------------------

    public function test_parked_orders_do_not_use_up_the_polling_limit(): void
    {
        $statusChecks = [];
        Http::fake(function ($request) use (&$statusChecks) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $statusChecks[] = $query['order'] ?? null;

            return Http::response(['status' => 'In progress'], 200);
        });

        $base = ['external_source' => '1xpanel', 'external_status' => SupplierOrderResult::PENDING];
        $this->supplierOrder('1xpanel', $base + ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => 'parked-a']);
        $this->supplierOrder('1xpanel', $base + ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => 'parked-b']);
        $this->supplierOrder('1xpanel', $base + ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => '555']);

        Artisan::call('1xpanel:check-orders', ['--limit' => 1]);

        $this->assertSame(['555'], $statusChecks);
    }

    public function test_polling_rotates_through_long_running_orders(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seen[] = $query['order'] ?? null;

            return Http::response(['status' => 'In progress'], 200);
        });

        // Rows already in the table must not take the only slot.
        Order::whereNotNull('id')->where('external_source', '1xpanel')->update(['external_status' => SupplierOrderResult::COMPLETED]);

        $base = ['external_source' => '1xpanel', 'external_status' => SupplierOrderResult::PENDING];
        $this->supplierOrder('1xpanel', $base + ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => '701']);
        $this->supplierOrder('1xpanel', $base + ['external_order_uuid' => (string) Str::uuid(), 'external_order_id' => '702']);

        $this->travel(1)->seconds();
        Artisan::call('1xpanel:check-orders', ['--limit' => 1]);
        $this->travel(1)->seconds();
        Artisan::call('1xpanel:check-orders', ['--limit' => 1]);

        $this->assertSame(['701', '702'], $seen);
    }

    // --- Queues -----------------------------------------------------------------------

    public function test_webhook_deliveries_have_their_own_queue(): void
    {
        $job = new DeliverOrderWebhookJob(1, 'evt', 'order.completed', '{}');

        $this->assertSame('webhooks', $job->queue);
    }

    public function test_a_rate_limited_placement_waits_for_the_supplier_window(): void
    {
        $job = new FulfillSupplierOrderJob(1);

        $this->assertGreaterThanOrEqual(5, $job->tries);
        $this->assertGreaterThanOrEqual(3600, max($job->backoff()));
    }

    public function test_a_placement_that_runs_out_of_tries_raises_a_critical(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('critical')->once()
            ->withArgs(fn ($message, $context) => $context['order_id'] === 42);

        (new FulfillSupplierOrderJob(42))->failed(new SupplierApiException('down'));
    }

    // --- Approval ---------------------------------------------------------------------

    public function test_one_supplier_error_does_not_strand_the_rest_of_a_bulk_approve(): void
    {
        config(['queue.default' => 'sync']);
        \Illuminate\Support\Facades\Mail::fake();
        Http::fake(['*' => Http::response(['error' => 'Invalid key'], 401)]);

        $pending = ['statuses_id' => Order::STATUS_PENDING, 'credits_applied_status' => Order::STATUS_PENDING];
        $first = $this->supplierOrder('1xpanel', $pending);
        $second = $this->supplierOrder('1xpanel', $pending);

        $admin = new \Hellotreedigital\Cms\Models\Admin();
        $admin->name = 'Bulk';
        $admin->email = 'bulk_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->save();

        $this->actingAs($admin->refresh(), 'admin')->put('/' . config('hellotree.cms_route_prefix') . '/orders/bulk-status', [
            'ids' => "{$first->id},{$second->id}",
            'statuses_id' => Order::STATUS_APPROVED,
        ])->assertRedirect();

        foreach ([$first, $second] as $order) {
            $this->assertSame(Order::STATUS_APPROVED, (int) $order->fresh()->statuses_id);
            $this->assertSame('1xpanel', $order->fresh()->external_source, 'Supplier health lists it by this column');
        }
    }

    // --- Pricing unit -----------------------------------------------------------------

    public function test_a_supplier_row_keeps_its_source_visible_when_approval_dispatch_fails(): void
    {
        $order = $this->supplierOrder('1xpanel', [
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);
        $this->assertSame('1xpanel', SupplierOrderFulfillment::sourceOf($order));
    }
}
