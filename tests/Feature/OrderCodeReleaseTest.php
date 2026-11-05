<?php

namespace Tests\Feature;

use App\Models\User;
use App\Order;
use App\Services\Suppliers\SupplierOrderFulfillment;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * A rejected order is refunded but its supplier order keeps being polled, so a voucher code
 * can land on it afterwards. Only the React components hid it from the owner.
 * Regression from the 2026-11 security audit (run-2).
 */
class OrderCodeReleaseTest extends TestCase
{
    use CreatesCatalog;

    private function user(): User
    {
        return User::create([
            'username' => 'buyer_' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, int $status): Order
    {
        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(10.00)->id,
            'quantity' => 1,
            'total_price' => 10.00,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
            'code' => '<ul><li>DUMMY-CODE</li></ul>',
        ]);
    }

    public function test_the_owner_cannot_read_the_code_of_a_rejected_order(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_REJECTED);
        Sanctum::actingAs($user);

        $this->getJson('/api/en/user/orders/' . $order->id)->assertOk()->assertJsonPath('order.code', null);
        $this->getJson('/api/en/user/orders')->assertOk()->assertDontSee('DUMMY-CODE');
        $this->getJson('/api/en/user/profile')->assertOk()->assertDontSee('DUMMY-CODE');
    }

    public function test_the_owner_cannot_read_the_code_of_a_pending_order(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_PENDING);
        Sanctum::actingAs($user);

        $this->getJson('/api/en/user/orders/' . $order->id)->assertOk()->assertDontSee('DUMMY-CODE');
    }

    public function test_the_owner_can_read_the_code_of_an_approved_order(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_APPROVED);
        Sanctum::actingAs($user);

        $this->getJson('/api/en/user/orders/' . $order->id)->assertOk()->assertSee('DUMMY-CODE');
    }

    public function test_the_stored_code_survives_for_a_later_re_approval(): void
    {
        $order = $this->order($this->user(), Order::STATUS_REJECTED);

        $this->assertStringContainsString('DUMMY-CODE', $order->refresh()->code);
    }

    public function test_a_supplier_completion_after_a_refund_is_flagged(): void
    {
        $order = $this->order($this->user(), Order::STATUS_REJECTED);
        $order->external_source = 'umanage';
        $order->external_order_id = 'bundle:55';
        $order->external_status = 'pending';
        $order->external_order_uuid = (string) \Illuminate\Support\Str::uuid();
        $order->save();

        config(['services.umanage.base_url' => 'https://umanage.test', 'services.umanage.key' => 'k', 'services.umanage.secret' => 's', 'services.umanage.store_id' => 1, 'services.umanage.enabled' => true]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'order' => ['order_id' => 55, 'status' => 'completed']], 200)]);
        Log::spy();

        app(SupplierOrderFulfillment::class)->refreshStatus($order);

        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'completed after'))->once();
        $this->assertEquals(Order::STATUS_REJECTED, $order->refresh()->statuses_id);
    }
}
