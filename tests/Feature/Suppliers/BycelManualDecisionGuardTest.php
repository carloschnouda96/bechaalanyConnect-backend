<?php

namespace Tests\Feature\Suppliers;

use App\BycelPurchase;
use App\Models\User;
use App\Order;
use Hellotreedigital\Cms\Models\Admin;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Abandon & refund and Assign were offered only for ambiguous purchases, but the routes took
 * any id: abandoning a delivered purchase refunded the customer while they kept the PIN.
 * Regression from the 2026-11 security audit (run-2).
 */
class BycelManualDecisionGuardTest extends TestCase
{
    use CreatesCatalog;

    private function superAdmin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Super';
        $admin->email = 'super_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    /** @return array{0: User, 1: Order, 2: BycelPurchase} */
    private function purchase(string $state, ?int $merchantPurchaseId = null): array
    {
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

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(25.00)->id,
            'quantity' => 1,
            'total_price' => 25.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_source' => 'bycel',
            'external_order_uuid' => $uuid,
            'external_order_id' => 'voucher:' . ($merchantPurchaseId ?? $uuid),
            'external_status' => $merchantPurchaseId ? 'completed' : 'pending',
            'code' => $merchantPurchaseId ? '<ul><li>DUMMY-PIN</li></ul>' : null,
        ]);

        $purchase = BycelPurchase::create([
            'order_id' => $order->id,
            'order_uuid' => $uuid,
            'family' => 'voucher',
            'product_id' => '11',
            'watermark_id' => 100,
            'merchant_purchase_id' => $merchantPurchaseId,
            'state' => $state,
            'snapshot' => [],
        ]);

        return [$user, $order, $purchase];
    }

    public function test_abandoning_a_claimed_purchase_changes_nothing(): void
    {
        [$user, $order, $purchase] = $this->purchase(BycelPurchase::STATE_CLAIMED, 150);

        $this->actingAs($this->superAdmin(), 'admin')
            ->put('/admin/supplier-health/bycel/' . $purchase->id . '/abandon')
            ->assertRedirect();

        $this->assertSame(BycelPurchase::STATE_CLAIMED, $purchase->refresh()->state);
        $this->assertEquals(Order::STATUS_APPROVED, $order->refresh()->statuses_id);
        $this->assertEquals(0, (float) $user->refresh()->credits_balance);
    }

    public function test_abandoning_an_ambiguous_purchase_still_refunds(): void
    {
        [$user, $order, $purchase] = $this->purchase(BycelPurchase::STATE_AMBIGUOUS);

        $this->actingAs($this->superAdmin(), 'admin')
            ->put('/admin/supplier-health/bycel/' . $purchase->id . '/abandon')
            ->assertRedirect();

        $this->assertSame(BycelPurchase::STATE_ABANDONED, $purchase->refresh()->state);
        $this->assertEquals(Order::STATUS_REJECTED, $order->refresh()->statuses_id);
        $this->assertEquals(25, (float) $user->refresh()->credits_balance);
    }

    public function test_assigning_a_row_to_an_abandoned_purchase_changes_nothing(): void
    {
        [, $order, $purchase] = $this->purchase(BycelPurchase::STATE_ABANDONED);
        $purchase->forceFill(['report_row' => ['candidates' => [['MerchantPurchaseId' => 888]]]])->save();
        // A live report row, so that only the state check can stop the claim.
        config(['services.bycel.base_url' => 'https://bycel.test', 'services.bycel.key' => 'k']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            ['MerchantPurchaseId' => 888, 'PinCode' => 'DUMMY-PIN-888', 'Serial' => '1'],
        ], 200)]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->put('/admin/supplier-health/bycel/' . $purchase->id . '/claim/888')
            ->assertRedirect();

        $this->assertSame(BycelPurchase::STATE_ABANDONED, $purchase->refresh()->state);
        $this->assertNull($purchase->merchant_purchase_id);
        $this->assertNull($order->refresh()->code);
    }

    /** A proven gate rejection bought nothing, so the job retry re-opens the same row. */
    public function test_a_failed_intent_can_be_reopened_for_a_retry(): void
    {
        [, $order, $purchase] = $this->purchase(BycelPurchase::STATE_FAILED);

        // openIntent() refuses to run inside a transaction, which every test is.
        $ledger = new class extends \App\Services\Bycel\BycelPurchaseLedger {
            public function reopen(Order $order): BycelPurchase
            {
                return $this->writeIntent($order, 'voucher', '11', [], 200, null, (string) $order->external_order_uuid);
            }
        };

        $reopened = $ledger->reopen($order);

        $this->assertSame($purchase->id, $reopened->id);
        $this->assertSame(BycelPurchase::STATE_SENT, $reopened->state);
        $this->assertEquals(200, $reopened->watermark_id);
    }
}
