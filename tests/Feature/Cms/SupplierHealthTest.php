<?php

namespace Tests\Feature\Cms;

use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class SupplierHealthTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Ops';
        $admin->email = 'ops_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function unplacedOrder(): Order
    {
        $user = User::create([
            'username' => 'sh_' . uniqid(),
            'email' => 'sh_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation()->id,
            'quantity' => 1,
            'total_price' => 12.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_source' => 'umanage',
            'external_order_id' => null,
        ]);
    }

    private function url(string $path = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/supplier-health' . $path;
    }

    public function test_page_lists_orders_that_never_reached_the_supplier(): void
    {
        $order = $this->unplacedOrder();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url())
            ->assertOk()
            ->assertSee('#' . $order->id, false)
            ->assertSee('Approved but never sent to the supplier', false);
    }

    public function test_page_requires_an_admin(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_retry_queues_fulfillment(): void
    {
        Queue::fake();
        $order = $this->unplacedOrder();

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/retry/' . $order->id))
            ->assertRedirect();

        Queue::assertPushed(FulfillSupplierOrderJob::class);
    }

    /** Retrying something already placed must not re-send it to the supplier. */
    public function test_retry_refuses_an_order_already_placed(): void
    {
        Queue::fake();
        $order = $this->unplacedOrder();
        $order->update(['external_order_id' => 'SUP-123']);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/retry/' . $order->id))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_retry_refuses_an_order_that_is_not_approved(): void
    {
        Queue::fake();
        $order = $this->unplacedOrder();
        $order->update(['statuses_id' => Order::STATUS_PENDING]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/retry/' . $order->id))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }
}
