<?php

namespace Tests\Feature\Cms;

use App\CreditLedgerEntry;
use App\CreditsTransfer;
use App\Models\User;
use App\Order;
use App\Services\Credits\CreditLedger;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The "Deleted records" page.
 *
 * orders, credits_transfer and users soft-delete on purpose, and the vendor CMS never
 * calls withTrashed() — so before this page a deleted row was invisible everywhere while
 * still holding the RESTRICT foreign keys that block deleting a product variation.
 */
class DeletedRecordsTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Ops';
        $admin->email = 'trash_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(float $balance = 0): User
    {
        return User::create([
            'username' => 'tr_' . uniqid(),
            'email' => 'tr_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'users_id' => $this->user()->id,
            'product_variation_id' => $this->createVariation()->id,
            'quantity' => 1,
            'total_price' => 9.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }

    private function url(string $path = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/deleted-records' . $path;
    }

    public function test_page_requires_an_admin(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_page_lists_a_soft_deleted_order(): void
    {
        $order = $this->order();
        $order->delete();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url())
            ->assertOk()
            ->assertSee('#' . $order->id, false)
            ->assertSee('Deleted orders', false);
    }

    public function test_page_does_not_list_a_live_order(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url())
            ->assertOk()
            ->assertDontSee('#' . $order->id, false);
    }

    public function test_a_soft_deleted_order_is_hidden_from_the_normal_orders_page(): void
    {
        $order = $this->order();
        $order->delete();

        // The premise of the whole page: this is why the row was invisible.
        $this->assertNull(Order::find($order->id));
        $this->assertNotNull(Order::withTrashed()->find($order->id));
    }

    public function test_restore_clears_deleted_at_and_moves_no_credits(): void
    {
        $order = $this->order();
        $order->delete();

        $balanceBefore = User::find($order->users_id)->credits_balance;

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/orders/' . $order->id . '/restore'))
            ->assertRedirect();

        $restored = Order::find($order->id);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        // credits_applied_status untouched, so the ledger and the order still agree.
        $this->assertSame(Order::STATUS_APPROVED, (int) $restored->credits_applied_status);
        $this->assertEquals($balanceBefore, User::find($order->users_id)->credits_balance);
    }

    public function test_purge_removes_the_row_for_good(): void
    {
        $order = $this->order();
        $order->delete();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->url('/orders/' . $order->id))
            ->assertRedirect();

        $this->assertNull(Order::withTrashed()->find($order->id));
    }

    public function test_purging_a_user_with_ledger_entries_reports_instead_of_throwing(): void
    {
        $user = $this->user(50);

        DB::transaction(function () use ($user) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            CreditLedger::record(
                $locked,
                10.00,
                CreditLedgerEntry::REASON_ADMIN_ADJUSTMENT,
                null,
                [],
                'trash-test-' . uniqid()
            );
        });

        $user->delete();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->url('/users/' . $user->id))
            ->assertRedirect();

        // credit_ledger_entries.user_id is ON DELETE RESTRICT — the refusal is the point.
        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertStringContainsString('credit ledger', session('success'));
    }

    public function test_an_unknown_type_is_not_routable(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->delete($this->url('/admins/1'))
            ->assertNotFound();
    }

    public function test_the_orders_page_links_to_this_one(): void
    {
        // Discoverability is the actual fix here: an admin who just deleted a row had no
        // way to find out it still existed.
        $this->actingAs($this->admin(), 'admin')
            ->get('/' . config('hellotree.cms_route_prefix') . '/orders')
            ->assertOk()
            ->assertSee($this->url(), false)
            ->assertSee('Deleted records', false);
    }

    public function test_page_lists_a_soft_deleted_credit_transfer(): void
    {
        $transfer = CreditsTransfer::create([
            'users_id' => $this->user()->id,
            'credits_types_id' => DB::table('credits_types')->value('id'),
            'amount' => 25.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
        ]);
        $transfer->delete();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url())
            ->assertOk()
            ->assertSee('Deleted credit transfers', false)
            ->assertSee('#' . $transfer->id, false);
    }
}
