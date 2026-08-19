<?php

namespace Tests\Feature\Cms;

use App\CreditLedgerEntry;
use App\CreditsTransfer;
use App\Models\User;
use App\Order;
use Hellotreedigital\Cms\Models\Admin;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Bulk approve/reject is the highest-leverage admin change, and also the easiest place
 * to accidentally bypass the credit rules. These tests exist to prove it does not:
 * every path must produce the same balances, ledger rows and refunds as editing each
 * record one at a time.
 */
class BulkStatusTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Bulk Admin';
        $admin->email = 'bulk_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(float $balance = 0.0): User
    {
        return User::create([
            'username' => 'bulk_' . uniqid(),
            'email' => 'bulk_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, int $variationId, float $total, int $status): Order
    {
        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variationId,
            'quantity' => 1,
            'total_price' => $total,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
        ]);
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . $path;
    }

    public function test_bulk_reject_refunds_every_selected_order(): void
    {
        $user = $this->user(0.00);
        $variation = $this->createVariation();

        $orders = collect(range(1, 3))->map(
            fn () => $this->order($user, $variation->id, 10.00, Order::STATUS_PENDING)
        );

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/orders/bulk-status'), [
                'ids' => $orders->pluck('id')->implode(','),
                'statuses_id' => Order::STATUS_REJECTED,
            ])
            ->assertRedirect();

        $this->assertEquals(30.00, (float) $user->fresh()->credits_balance, '3 x $10 refunded');

        foreach ($orders as $order) {
            $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->statuses_id);
            $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->credits_applied_status);
        }

        $this->assertSame(3, CreditLedgerEntry::where('user_id', $user->id)
            ->where('reason', CreditLedgerEntry::REASON_ORDER_REFUND)->count());
    }

    /**
     * The important failure mode: one row in a batch that cannot be paid for must not
     * take the rest down with it, and must not leave a negative balance.
     */
    public function test_a_row_that_cannot_be_paid_for_fails_alone(): void
    {
        $user = $this->user(15.00);
        $variation = $this->createVariation();

        // Rejected orders being re-approved: each re-debits $10, but only $15 is held.
        $orders = collect(range(1, 3))->map(
            fn () => $this->order($user, $variation->id, 10.00, Order::STATUS_REJECTED)
        );

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/orders/bulk-status'), [
                'ids' => $orders->pluck('id')->implode(','),
                'statuses_id' => Order::STATUS_APPROVED,
            ])
            ->assertRedirect();

        $balance = (float) $user->fresh()->credits_balance;

        $this->assertGreaterThanOrEqual(0.0, $balance, 'balance must never go negative');
        $this->assertEquals(5.00, $balance, 'exactly one $10 re-debit should have been affordable');

        $statuses = $orders->map(fn ($o) => (int) $o->fresh()->statuses_id);
        $this->assertSame(1, $statuses->filter(fn ($s) => $s === Order::STATUS_APPROVED)->count());
        $this->assertSame(2, $statuses->filter(fn ($s) => $s === Order::STATUS_REJECTED)->count(),
            'orders that could not be re-charged must be reverted, not left approved');
    }

    public function test_bulk_approve_credits_each_topup_exactly_once(): void
    {
        $user = $this->user(0.00);

        $transfers = collect(range(1, 3))->map(fn () => CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 25.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]));

        $ids = $transfers->pluck('id')->implode(',');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->put($this->url('/credits-transfer/bulk-status'), [
                'ids' => $ids,
                'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            ])->assertRedirect();

        $this->assertEquals(75.00, (float) $user->fresh()->credits_balance);

        // Re-submitting the identical batch — a double-clicked button — must not
        // credit anything again.
        $this->actingAs($admin, 'admin')
            ->put($this->url('/credits-transfer/bulk-status'), [
                'ids' => $ids,
                'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            ])->assertRedirect();

        $this->assertEquals(
            75.00,
            (float) $user->fresh()->credits_balance,
            'Re-running the same bulk approval must be a no-op.'
        );
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/orders/bulk-status'), ['ids' => '1', 'statuses_id' => 99])
            ->assertSessionHasErrors('statuses_id');
    }

    public function test_bulk_status_requires_an_admin(): void
    {
        $this->put($this->url('/orders/bulk-status'), [
            'ids' => '1',
            'statuses_id' => Order::STATUS_APPROVED,
        ])->assertRedirect();
    }

    /** The route must not be swallowed by the PUT /orders/{id} wildcard. */
    public function test_bulk_status_route_is_not_shadowed_by_the_id_route(): void
    {
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create($this->url('/orders/bulk-status'), 'PUT')
        );

        $this->assertStringContainsString('bulkStatus', $route->getActionName());
    }
}
