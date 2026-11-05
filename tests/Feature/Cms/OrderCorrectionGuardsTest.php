<?php

namespace Tests\Feature\Cms;

use App\CreditsTransfer;
use App\CreditsType;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\Services\Suppliers\SupplierOrderResult;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Two admin actions that used to keep a customer's money with nothing to show for it:
 * deleting a paid order, and re-approving an order the supplier already failed.
 */
class OrderCorrectionGuardsTest extends TestCase
{
    use CreatesCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Guard Admin';
        $admin->email = 'guard_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(float $balance = 0): User
    {
        return User::create([
            'username' => 'guard_' . uniqid(),
            'email' => 'guard_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, int $status, array $extra = []): Order
    {
        return Order::create($extra + [
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(10)->id,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
        ]);
    }

    private function transfer(User $user, int $status): CreditsTransfer
    {
        return CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 10,
            'credits_types_id' => CreditsType::create(['slug' => 'guard-' . uniqid()])->id,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
            'receipt_image' => 'receipts/x.png',
        ]);
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    public function test_a_pending_or_approved_order_cannot_be_deleted(): void
    {
        $user = $this->user();

        foreach ([Order::STATUS_PENDING, Order::STATUS_APPROVED] as $status) {
            $order = $this->order($user, $status);

            $this->actingAs($this->admin(), 'admin')->delete($this->url("orders/{$order->id}"))->assertRedirect();

            $this->assertNotNull(Order::find($order->id), "status {$status} must survive");
        }
    }

    public function test_a_rejected_order_can_be_deleted(): void
    {
        $order = $this->order($this->user(), Order::STATUS_REJECTED);

        $this->actingAs($this->admin(), 'admin')->delete($this->url("orders/{$order->id}"))->assertRedirect();

        $this->assertNull(Order::find($order->id));
        $this->assertNotNull(Order::withTrashed()->find($order->id));
    }

    public function test_bulk_delete_removes_only_the_orders_holding_no_credits(): void
    {
        $user = $this->user();
        $held = $this->order($user, Order::STATUS_PENDING);
        $released = $this->order($user, Order::STATUS_REJECTED);

        $this->actingAs($this->admin(), 'admin')
            ->from($this->url('orders'))
            ->delete($this->url("orders/{$held->id},{$released->id}"))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, "#{$held->id}"));

        $this->assertNotNull(Order::find($held->id));
        $this->assertNull(Order::find($released->id));
    }

    public function test_an_approved_topup_cannot_be_deleted_but_a_pending_one_can(): void
    {
        $user = $this->user();
        $approved = $this->transfer($user, CreditsTransfer::STATUS_APPROVED);
        $pending = $this->transfer($user, CreditsTransfer::STATUS_PENDING);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete($this->url("credits-transfer/{$approved->id}"))->assertRedirect();
        $this->actingAs($admin, 'admin')->delete($this->url("credits-transfer/{$pending->id}"))->assertRedirect();

        $this->assertNotNull(CreditsTransfer::find($approved->id));
        $this->assertNull(CreditsTransfer::find($pending->id));
    }

    private function failedSupplierOrder(User $user): Order
    {
        return $this->order($user, Order::STATUS_REJECTED, [
            'external_source' => 'swift',
            'external_order_id' => 'X1',
            'external_order_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'external_status' => SupplierOrderResult::FAILED,
        ]);
    }

    public function test_an_order_the_supplier_failed_cannot_be_reapproved(): void
    {
        $user = $this->user(30);
        $order = $this->failedSupplierOrder($user);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_APPROVED])
            ->assertSessionHasErrors('statuses_id');

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_REJECTED, (int) $fresh->statuses_id);
        $this->assertSame(Order::STATUS_REJECTED, (int) $fresh->credits_applied_status);
        $this->assertEquals(30.0, (float) $user->fresh()->credits_balance);
    }

    public function test_bulk_status_refuses_a_failed_supplier_order_and_applies_the_rest(): void
    {
        $user = $this->user(30);
        $failed = $this->failedSupplierOrder($user);
        $ordinary = $this->order($user, Order::STATUS_REJECTED);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('orders/bulk-status'), ['ids' => "{$failed->id},{$ordinary->id}", 'statuses_id' => Order::STATUS_APPROVED])
            ->assertRedirect();

        $this->assertSame(Order::STATUS_REJECTED, (int) $failed->fresh()->statuses_id);
        $this->assertSame(Order::STATUS_APPROVED, (int) $ordinary->fresh()->statuses_id);
        $this->assertEquals(20.0, (float) $user->fresh()->credits_balance);
    }

    public function test_an_order_rejected_by_staff_can_still_be_reapproved(): void
    {
        $user = $this->user(30);
        $order = $this->order($user, Order::STATUS_REJECTED);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_APPROVED]);

        $this->assertSame(Order::STATUS_APPROVED, (int) $order->fresh()->statuses_id);
        $this->assertEquals(20.0, (float) $user->fresh()->credits_balance);
        Queue::assertNotPushed(FulfillSupplierOrderJob::class);
    }
}
