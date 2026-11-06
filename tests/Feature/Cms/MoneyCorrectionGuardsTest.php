<?php

namespace Tests\Feature\Cms;

use App\CreditLedgerEntry;
use App\CreditsTransfer;
use App\CreditsType;
use App\Models\User;
use App\Order;
use App\Services\Cms\CatalogImporter;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regressions from security audit run-4: staff corrections that moved the wrong money. */
class MoneyCorrectionGuardsTest extends TestCase
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
        $admin->name = 'Money Admin';
        $admin->email = 'money_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(float $balance = 100): User
    {
        return User::create([
            'username' => 'money_' . uniqid(),
            'email' => 'money_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function transfer(User $user, int $status, float $amount = 10, ?int $typeId = null): CreditsTransfer
    {
        return CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => $amount,
            'credits_types_id' => $typeId ?? CreditsType::create(['slug' => 'money-' . uniqid()])->id,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
            'receipt_image' => 'receipts/x.png',
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

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    /** The fields the vendor form requires on every save. */
    private function formFor(CreditsTransfer $transfer, array $overrides): array
    {
        return $overrides + [
            'users_id' => $transfer->users_id,
            'amount' => $transfer->amount,
            'credits_types_id' => $transfer->credits_types_id,
        ];
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin(), 'admin');
    }

    // --- Settled top-ups ------------------------------------------------------------

    public function test_a_settled_topups_owner_cannot_change(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $transfer = $this->transfer($owner, CreditsTransfer::STATUS_APPROVED);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), [
            'statuses_id' => CreditsTransfer::STATUS_REJECTED,
            'users_id' => $other->id,
        ])->assertSessionHasErrors('users_id');

        $fresh = $transfer->fresh();
        $this->assertSame($owner->id, (int) $fresh->users_id);
        $this->assertSame(CreditsTransfer::STATUS_APPROVED, (int) $fresh->statuses_id);
        $this->assertEquals(100.0, (float) $owner->fresh()->credits_balance);
        $this->assertEquals(100.0, (float) $other->fresh()->credits_balance);
    }

    public function test_a_settled_topups_amount_cannot_change(): void
    {
        $owner = $this->user();
        $transfer = $this->transfer($owner, CreditsTransfer::STATUS_APPROVED);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), [
            'statuses_id' => CreditsTransfer::STATUS_REJECTED,
            'amount' => 1000,
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(10.0, (float) $transfer->fresh()->amount);
        $this->assertEquals(100.0, (float) $owner->fresh()->credits_balance);
    }

    public function test_a_pending_topup_can_be_corrected_and_approved_in_one_save(): void
    {
        $owner = $this->user();
        $transfer = $this->transfer($owner, CreditsTransfer::STATUS_PENDING);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), $this->formFor($transfer, [
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'amount' => 25,
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(125.0, (float) $owner->fresh()->credits_balance);
    }

    public function test_a_negative_amount_is_refused_on_save(): void
    {
        $owner = $this->user();
        $transfer = $this->transfer($owner, CreditsTransfer::STATUS_PENDING);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), [
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'amount' => -50,
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(100.0, (float) $owner->fresh()->credits_balance);
        $this->assertSame(CreditsTransfer::STATUS_PENDING, (int) $transfer->fresh()->statuses_id);
    }

    public function test_bulk_approve_refuses_a_stored_amount_of_zero_or_less(): void
    {
        $owner = $this->user();
        $bad = $this->transfer($owner, CreditsTransfer::STATUS_PENDING, -50);

        $this->asAdmin()->put($this->url('credits-transfer/bulk-status'), [
            'ids' => (string) $bad->id,
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertSame(CreditsTransfer::STATUS_PENDING, (int) $bad->fresh()->statuses_id);
        $this->assertEquals(100.0, (float) $owner->fresh()->credits_balance);
    }

    public function test_a_topup_cannot_be_created_from_the_cms(): void
    {
        $before = DB::table('credits_transfer')->count();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get($this->url('credits-transfer/create'))->assertForbidden();
        $this->actingAs($admin, 'admin')->post($this->url('credits-transfer'), ['amount' => 500])->assertForbidden();

        $this->assertSame($before, DB::table('credits_transfer')->count());
    }

    public function test_the_draft_flag_cannot_blank_a_settled_topups_amount(): void
    {
        $owner = $this->user();
        $transfer = $this->transfer($owner, CreditsTransfer::STATUS_APPROVED);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), $this->formFor($transfer, [
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'draft_cms_field' => 1,
        ]));

        $this->assertEquals(10.0, (float) $transfer->fresh()->amount);
        $this->assertSame($owner->id, (int) $transfer->fresh()->users_id);
    }

    // --- Delivered orders -----------------------------------------------------------

    private function deliveredOrder(User $user): Order
    {
        return $this->order($user, Order::STATUS_APPROVED, [
            'external_source' => 'swift',
            'external_order_id' => 'X1',
            'external_order_uuid' => (string) Str::uuid(),
            'external_status' => SupplierOrderResult::COMPLETED,
        ]);
    }

    public function test_a_delivered_order_cannot_be_rejected(): void
    {
        $user = $this->user();
        $order = $this->deliveredOrder($user);

        $this->asAdmin()->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_REJECTED])
            ->assertSessionHasErrors('statuses_id');

        $this->assertSame(Order::STATUS_APPROVED, (int) $order->fresh()->statuses_id);
        $this->assertEquals(100.0, (float) $user->fresh()->credits_balance);
    }

    public function test_bulk_reject_refuses_a_delivered_order_and_refunds_the_rest(): void
    {
        $user = $this->user();
        $delivered = $this->deliveredOrder($user);
        $ordinary = $this->order($user, Order::STATUS_PENDING);

        $this->asAdmin()->put($this->url('orders/bulk-status'), [
            'ids' => "{$delivered->id},{$ordinary->id}",
            'statuses_id' => Order::STATUS_REJECTED,
        ])->assertRedirect();

        $this->assertSame(Order::STATUS_APPROVED, (int) $delivered->fresh()->statuses_id);
        $this->assertSame(Order::STATUS_REJECTED, (int) $ordinary->fresh()->statuses_id);
        $this->assertEquals(110.0, (float) $user->fresh()->credits_balance);
    }

    public function test_an_order_still_pending_at_the_supplier_can_be_rejected(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_APPROVED, [
            'external_source' => 'swift',
            'external_order_id' => 'parked-1',
            'external_order_uuid' => (string) Str::uuid(),
            'external_status' => SupplierOrderResult::PENDING,
        ]);

        $this->asAdmin()->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_REJECTED]);

        $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->statuses_id);
        $this->assertEquals(110.0, (float) $user->fresh()->credits_balance);
    }

    // --- Soft-deleted owners ---------------------------------------------------------

    public function test_rejecting_a_soft_deleted_customers_order_moves_the_money(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_PENDING);
        $user->delete();

        $this->asAdmin()->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_REJECTED]);

        $this->assertEquals(110.0, (float) User::withTrashed()->find($user->id)->credits_balance);
        $this->assertSame(1, CreditLedgerEntry::where('user_id', $user->id)->count());
    }

    // --- Supplier refund during a correction -----------------------------------------

    private function runRefund(Order $order): void
    {
        $method = new \ReflectionMethod(SupplierOrderFulfillment::class, 'refund');
        $method->invoke(app(SupplierOrderFulfillment::class), $order);
    }

    public function test_a_supplier_failure_during_a_reapproval_does_not_refund_twice(): void
    {
        $user = $this->user();
        // Already rejected and refunded; an admin has set it Approved but it is not settled yet.
        $order = $this->order($user, Order::STATUS_APPROVED, ['credits_applied_status' => Order::STATUS_REJECTED]);

        $this->runRefund($order);

        $this->assertEquals(100.0, (float) $user->fresh()->credits_balance);
        $this->assertSame(0, CreditLedgerEntry::where('user_id', $user->id)->count());
        $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->statuses_id);
    }

    public function test_a_supplier_failure_still_refunds_a_held_order(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_APPROVED);

        $this->runRefund($order);

        $this->assertEquals(110.0, (float) $user->fresh()->credits_balance);
    }

    public function test_a_supplier_failure_refunds_a_soft_deleted_customer(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_APPROVED);
        $user->delete();

        $this->runRefund($order);

        $this->assertEquals(110.0, (float) User::withTrashed()->find($user->id)->credits_balance);
    }

    // --- Payment methods ------------------------------------------------------------

    public function test_a_payment_method_with_topups_cannot_be_deleted(): void
    {
        $type = CreditsType::create(['slug' => 'method-' . uniqid()]);
        $transfer = $this->transfer($this->user(), CreditsTransfer::STATUS_APPROVED, 10, $type->id);

        $this->asAdmin()->delete($this->url("credits-types/{$type->id}"))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'top-up'));

        $this->assertTrue(DB::table('credits_types')->where('id', $type->id)->exists());
        $this->assertTrue(DB::table('credits_transfer')->where('id', $transfer->id)->exists());
    }

    public function test_a_soft_deleted_topup_also_keeps_its_payment_method(): void
    {
        $type = CreditsType::create(['slug' => 'method-' . uniqid()]);
        $transfer = $this->transfer($this->user(), CreditsTransfer::STATUS_REJECTED, 10, $type->id);
        $transfer->delete();

        $this->asAdmin()->delete($this->url("credits-types/{$type->id}"))->assertRedirect();

        $this->assertTrue(DB::table('credits_types')->where('id', $type->id)->exists());
    }

    public function test_an_unused_payment_method_can_be_deleted(): void
    {
        $type = CreditsType::create(['slug' => 'method-' . uniqid()]);

        $this->asAdmin()->delete($this->url("credits-types/{$type->id}"))->assertRedirect();

        $this->assertFalse(DB::table('credits_types')->where('id', $type->id)->exists());
    }

    public function test_the_database_also_refuses_to_cascade_a_payment_method(): void
    {
        $type = CreditsType::create(['slug' => 'method-' . uniqid()]);
        $this->transfer($this->user(), CreditsTransfer::STATUS_APPROVED, 10, $type->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('credits_types')->where('id', $type->id)->delete();
    }

    // --- Catalog import --------------------------------------------------------------

    public function test_a_csv_row_with_a_new_product_slug_cannot_take_a_supplier_variation(): void
    {
        $variation = $this->createVariation(12);
        $variation->forceFill(['external_id' => 'ext-1', 'external_source' => 'swift'])->save();
        $product = $variation->product_id;
        $subcategory = $variation->product->subcategory;

        $row = [
            'product_slug' => 'brand-new-' . uniqid(),
            'subcategory_slug' => $subcategory->slug,
            'category_slug' => $subcategory->category->slug,
            'product_type_id' => 2,
            'variation_slug' => $variation->slug,
            'price' => '0.01',
        ];

        $plan = (new CatalogImporter())->plan([$row]);
        $this->assertSame(CatalogImporter::SKIPPED, $plan[0]['action']);

        (new CatalogImporter())->apply([$row]);

        $fresh = $variation->fresh();
        $this->assertSame((int) $product, (int) $fresh->product_id);
        $this->assertEquals(12.0, (float) $fresh->price);
    }

    public function test_a_csv_row_cannot_move_a_variation_from_another_product(): void
    {
        $variation = $this->createVariation(12);
        $other = $this->createVariation(8);

        $row = [
            'product_slug' => $other->product->slug,
            'variation_slug' => $variation->slug,
            'price' => '0.01',
        ];

        (new CatalogImporter())->apply([$row]);

        $fresh = $variation->fresh();
        $this->assertSame((int) $variation->product_id, (int) $fresh->product_id);
        $this->assertEquals(12.0, (float) $fresh->price);
    }
}
