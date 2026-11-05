<?php

namespace Tests\Feature\Credits;

use App\CreditsTransfer;
use App\CreditsType;
use App\Http\Controllers\Cms\CreditsController;
use App\Http\Controllers\Cms\OrdersController;
use App\Models\User;
use App\Order;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Staff correct their own mistakes, so one record can pass through the same transition
 * more than once. The idempotency key used to be the transition alone, so the second
 * Approved → Rejected collided with the first, rolled back, and was reported as success
 * while the status stayed changed: a Rejected top-up keeping its credits. Balance and
 * ledger still agreed with each other, so credits:reconcile could not see it.
 */
class StatusCycleTest extends TestCase
{
    use CreatesCatalog;

    private function user(float $balance): User
    {
        return User::create([
            'username' => 'cycle_' . uniqid(),
            'email' => 'cycle_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function apply(string $controller, int $id): void
    {
        $instance = app($controller);
        (new \ReflectionMethod($instance, 'applyStatusChange'))->invoke($instance, $id);
    }

    public function test_every_topup_status_change_moves_the_money_it_should(): void
    {
        $user = $this->user(0);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 10,
            'credits_types_id' => CreditsType::create(['slug' => 'cycle-' . uniqid()])->id,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
            'receipt_image' => 'receipts/x.png',
        ]);

        $steps = [
            [CreditsTransfer::STATUS_APPROVED, 10.0],
            [CreditsTransfer::STATUS_REJECTED, 0.0],
            [CreditsTransfer::STATUS_APPROVED, 10.0],
            [CreditsTransfer::STATUS_REJECTED, 0.0],
            [CreditsTransfer::STATUS_APPROVED, 10.0],
            [CreditsTransfer::STATUS_REJECTED, 0.0],
        ];

        foreach ($steps as $i => [$status, $expected]) {
            $transfer->update(['statuses_id' => $status]);
            $this->apply(CreditsController::class, $transfer->id);

            $this->assertEquals($expected, (float) $user->fresh()->credits_balance, "balance after step {$i}");
            $this->assertSame($status, (int) $transfer->fresh()->credits_applied_status, "ledger view after step {$i}");
        }
    }

    public function test_every_order_status_change_moves_the_money_it_should(): void
    {
        $user = $this->user(50);
        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(10)->id,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);

        $steps = [
            [Order::STATUS_REJECTED, 60.0],
            [Order::STATUS_APPROVED, 50.0],
            [Order::STATUS_REJECTED, 60.0],
            [Order::STATUS_APPROVED, 50.0],
            [Order::STATUS_REJECTED, 60.0],
        ];

        foreach ($steps as $i => [$status, $expected]) {
            $order->update(['statuses_id' => $status]);
            $this->apply(OrdersController::class, $order->id);

            $this->assertEquals($expected, (float) $user->fresh()->credits_balance, "balance after step {$i}");
            $this->assertSame($status, (int) $order->fresh()->credits_applied_status, "ledger view after step {$i}");
        }
    }

    public function test_applying_the_same_status_twice_still_moves_money_once(): void
    {
        $user = $this->user(0);
        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 10,
            'credits_types_id' => CreditsType::create(['slug' => 'cycle-' . uniqid()])->id,
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
            'receipt_image' => 'receipts/x.png',
        ]);

        $this->apply(CreditsController::class, $transfer->id);
        $this->apply(CreditsController::class, $transfer->id);

        $this->assertEquals(10.0, (float) $user->fresh()->credits_balance);
    }

    public function test_reconcile_reports_a_status_the_ledger_has_not_applied(): void
    {
        $user = $this->user(50);
        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(10)->id,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_REJECTED,
        ]);

        $exit = Artisan::call('credits:reconcile');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('order ' . $order->id, Artisan::output());
    }
}
