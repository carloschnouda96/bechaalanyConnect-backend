<?php

namespace Tests\Feature\Credits;

use App\CreditLedgerEntry;
use App\CreditsTransfer;
use App\Http\Controllers\Cms\CreditsController;
use App\Http\Controllers\Cms\OrdersController;
use App\Models\User;
use App\Order;
use App\Services\Credits\InsufficientCreditsException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the credit consequences of every CMS status transition.
 *
 * The private applyStatusChange() is called directly rather than through the HTTP
 * route, so these tests cover the money logic without also depending on an admin
 * session and the vendor's form handling. The controller's public update() is a thin
 * wrapper: validate → vendor write → applyStatusChange → side effects.
 */
class StatusChangeTest extends TestCase
{
    use \Tests\Concerns\CreatesCatalog;

    private ?int $variationId = null;

    /** orders.product_variation_id now has a foreign key, so this must be a real row. */
    private function variationId(): int
    {
        return $this->variationId ??= $this->createVariation()->id;
    }

    private function user(float $balance = 0.0): User
    {
        return User::create([
            'username' => 'status_' . uniqid(),
            'email' => 'status_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, float $total, int $status, ?int $applied = null): Order
    {
        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->variationId(),
            'quantity' => 1,
            'total_price' => $total,
            'statuses_id' => $status,
            'credits_applied_status' => $applied ?? $status,
        ]);
    }

    private function applyOrder(int $orderId)
    {
        $controller = app(OrdersController::class);
        $method = new \ReflectionMethod($controller, 'applyStatusChange');
        $method->setAccessible(true);

        return $method->invoke($controller, $orderId);
    }

    private function applyTransfer(int $transferId)
    {
        $controller = app(CreditsController::class);
        $method = new \ReflectionMethod($controller, 'applyStatusChange');
        $method->setAccessible(true);

        return $method->invoke($controller, $transferId);
    }

    // ---------------------------------------------------------------- orders

    /** Credits were taken at placement, so approving must not take them again. */
    public function test_pending_to_approved_moves_no_money(): void
    {
        $user = $this->user(100.00);
        $order = $this->order($user, 25.00, Order::STATUS_PENDING);

        $order->update(['statuses_id' => Order::STATUS_APPROVED]);
        $this->applyOrder($order->id);

        $this->assertEquals(100.00, (float) $user->fresh()->credits_balance);
    }

    public function test_pending_to_rejected_refunds(): void
    {
        $user = $this->user(75.00);
        $order = $this->order($user, 25.00, Order::STATUS_PENDING);

        $order->update(['statuses_id' => Order::STATUS_REJECTED]);
        $this->applyOrder($order->id);

        $this->assertEquals(100.00, (float) $user->fresh()->credits_balance);
        $this->assertSame(
            CreditLedgerEntry::REASON_ORDER_REFUND,
            CreditLedgerEntry::where('user_id', $user->id)->latest('id')->first()->reason
        );
    }

    public function test_approved_to_rejected_refunds(): void
    {
        $user = $this->user(75.00);
        $order = $this->order($user, 25.00, Order::STATUS_APPROVED);

        $order->update(['statuses_id' => Order::STATUS_REJECTED]);
        $this->applyOrder($order->id);

        $this->assertEquals(100.00, (float) $user->fresh()->credits_balance);
    }

    public function test_rejected_to_approved_redebits(): void
    {
        $user = $this->user(100.00);
        $order = $this->order($user, 25.00, Order::STATUS_REJECTED);

        $order->update(['statuses_id' => Order::STATUS_APPROVED]);
        $this->applyOrder($order->id);

        $this->assertEquals(75.00, (float) $user->fresh()->credits_balance);
    }

    /**
     * The branch that had no balance check at all. Re-approving a rejected order
     * after the user has spent the refund used to drive the balance negative.
     */
    public function test_redebit_is_refused_when_the_user_cannot_cover_it(): void
    {
        $user = $this->user(5.00);
        $order = $this->order($user, 25.00, Order::STATUS_REJECTED);
        $order->update(['statuses_id' => Order::STATUS_APPROVED]);

        try {
            $this->applyOrder($order->id);
            $this->fail('Expected InsufficientCreditsException.');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame(Order::STATUS_REJECTED, $e->revertTo);
        }

        $this->assertEquals(5.00, (float) $user->fresh()->credits_balance, 'balance must not go negative');
        $this->assertSame(
            Order::STATUS_REJECTED,
            (int) $order->fresh()->credits_applied_status,
            'the ledger must still be settled at the old status'
        );
    }

    /**
     * THE RACE. Two admins acting on the same order both used to observe the same
     * pre-transaction status and both applied the refund.
     */
    public function test_applying_the_same_order_transition_twice_refunds_once(): void
    {
        $user = $this->user(75.00);
        $order = $this->order($user, 25.00, Order::STATUS_PENDING);
        $order->update(['statuses_id' => Order::STATUS_REJECTED]);

        $this->applyOrder($order->id);
        $this->applyOrder($order->id); // second admin / double submit / retry

        $this->assertEquals(
            100.00,
            (float) $user->fresh()->credits_balance,
            'The refund must be applied exactly once.'
        );

        $this->assertSame(
            1,
            CreditLedgerEntry::where('user_id', $user->id)
                ->where('reason', CreditLedgerEntry::REASON_ORDER_REFUND)
                ->count()
        );
    }

    public function test_total_purchases_tracks_the_balance(): void
    {
        $user = $this->user(100.00);
        DB::table('users')->where('id', $user->id)->update(['total_purchases' => 25.00]);

        $order = $this->order($user, 25.00, Order::STATUS_PENDING);
        $order->update(['statuses_id' => Order::STATUS_REJECTED]);
        $this->applyOrder($order->id);

        $fresh = $user->fresh();
        $this->assertEquals(125.00, (float) $fresh->credits_balance);
        $this->assertEquals(0.00, (float) $fresh->total_purchases, 'refund must also unwind lifetime spend');
    }

    // ------------------------------------------------------- credit transfers

    public function test_topup_approval_credits_the_user(): void
    {
        $user = $this->user(10.00);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 40.50,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);

        $transfer->update(['statuses_id' => CreditsTransfer::STATUS_APPROVED]);
        $this->applyTransfer($transfer->id);

        $this->assertEquals(50.50, (float) $user->fresh()->credits_balance);
        $this->assertEquals(40.50, (float) $user->fresh()->received_amount);
    }

    /**
     * The exact double-credit the audit found: two concurrent approvals of one
     * pending top-up.
     */
    public function test_approving_the_same_topup_twice_credits_once(): void
    {
        $user = $this->user(0.00);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 250.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);

        $transfer->update(['statuses_id' => CreditsTransfer::STATUS_APPROVED]);

        $this->applyTransfer($transfer->id);
        $this->applyTransfer($transfer->id);

        $this->assertEquals(
            250.00,
            (float) $user->fresh()->credits_balance,
            'A top-up approved twice must credit the user once.'
        );
    }

    public function test_reverting_an_approved_topup_takes_the_credit_back(): void
    {
        $user = $this->user(0.00);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 60.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);

        $transfer->update(['statuses_id' => CreditsTransfer::STATUS_APPROVED]);
        $this->applyTransfer($transfer->id);
        $this->assertEquals(60.00, (float) $user->fresh()->credits_balance);

        $transfer->update(['statuses_id' => CreditsTransfer::STATUS_REJECTED]);
        $this->applyTransfer($transfer->id);

        $this->assertEquals(0.00, (float) $user->fresh()->credits_balance);
    }

    /** Reverting an approval the user has already spent must not go negative. */
    public function test_reverting_a_spent_topup_is_refused(): void
    {
        $user = $this->user(0.00);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 60.00,
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'credits_applied_status' => CreditsTransfer::STATUS_APPROVED,
        ]);

        $transfer->update(['statuses_id' => CreditsTransfer::STATUS_REJECTED]);

        $this->expectException(InsufficientCreditsException::class);

        try {
            $this->applyTransfer($transfer->id);
        } finally {
            $this->assertEquals(0.00, (float) $user->fresh()->credits_balance);
        }
    }
}
