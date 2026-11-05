<?php

namespace Tests\Feature\Suppliers;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\SupplierOrderFulfillment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * fulfill()'s guard read a model that could be stale, with nothing stopping two workers or two
 * Retry clicks both passing it and each buying from a supplier that has no dedupe key.
 * Regression from the 2026-11 security audit (run-2).
 */
class FulfillmentReentryTest extends TestCase
{
    use CreatesCatalog;

    private function order(): Order
    {
        config(['services.1xpanel.base_url' => 'https://1xpanel.test', 'services.1xpanel.key' => 'k', 'services.1xpanel.enabled' => true]);

        $variation = $this->createVariation(10.00);
        $variation->forceFill(['external_source' => '1xpanel', 'external_id' => '1'])->save();

        $user = User::create([
            'username' => 'buyer_' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 100,
            'total_price' => 10.00,
            'recipient_user' => 'https://example.test/me',
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }

    public function test_a_second_worker_does_not_send_while_the_first_holds_the_order(): void
    {
        Http::fake();
        $order = $this->order();
        $lock = Cache::lock('supplier-fulfill:' . $order->id, 60);
        $lock->get();

        app(SupplierOrderFulfillment::class)->fulfill($order);

        Http::assertNothingSent();
        $lock->release();
    }

    public function test_a_stale_model_does_not_send_an_order_already_placed(): void
    {
        Http::fake();
        $order = $this->order();
        $stale = Order::find($order->id);
        $order->forceFill(['external_order_id' => '555', 'external_status' => 'pending'])->save();

        app(SupplierOrderFulfillment::class)->fulfill($stale);

        Http::assertNothingSent();
    }

    public function test_a_normal_order_is_sent_once(): void
    {
        Http::fake(['*' => Http::response(['order' => 777], 200)]);
        $order = $this->order();

        app(SupplierOrderFulfillment::class)->fulfill($order);

        Http::assertSentCount(1);
        $this->assertSame('777', $order->refresh()->external_order_id);
    }
}
