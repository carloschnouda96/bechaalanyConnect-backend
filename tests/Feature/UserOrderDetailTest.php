<?php

namespace Tests\Feature;

use App\Models\User;
use App\Order;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Backs the storefront's order status page (`/account-dashboard/my-orders/[id]`).
 *
 * The only place a customer previously saw a single order's state was the My Orders
 * list — no confirmation screen, no way to watch one order, and the list itself
 * auto-refreshed every 3 minutes. GET /user/orders/{id} lets the page poll one row.
 *
 * The property that matters most here: a customer must never be able to tell, by the
 * shape of the response, that an order belonging to someone else exists. That's why
 * "not mine" and "does not exist" are both a plain 404.
 */
class UserOrderDetailTest extends TestCase
{
    use CreatesCatalog;

    private function user(): User
    {
        return User::create([
            'username' => 'buyer_' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function url(int $id): string
    {
        return '/api/en/user/orders/' . $id;
    }

    public function test_the_owner_can_fetch_their_order(): void
    {
        $user = $this->user();
        $variation = $this->createVariation(12.00);

        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 12.00,
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson($this->url($order->id));

        $response->assertOk();
        $response->assertJsonPath('id', $order->id);
        $response->assertJsonPath('product_variation.id', $variation->id);
        $response->assertJsonPath('product_variation.product.id', $variation->product_id);
        $response->assertJsonPath('users.username', $user->username);
    }

    public function test_another_users_order_is_a_404(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $variation = $this->createVariation();

        $order = Order::create([
            'users_id' => $owner->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 5.00,
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);

        Sanctum::actingAs($stranger);

        $this->getJson($this->url($order->id))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_a_nonexistent_order_is_a_404(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson($this->url(999999999))->assertNotFound();
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->getJson($this->url(1))->assertUnauthorized();
    }

    /** Same allow-list as the list endpoint — no internal supplier bookkeeping. */
    public function test_the_response_hides_supplier_internals(): void
    {
        $user = $this->user();
        $variation = $this->createVariation();

        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 5.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_order_uuid' => 'uuid-should-not-leak',
            'external_response' => ['secret' => 'internal'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson($this->url($order->id));

        $response->assertOk();
        $response->assertJsonMissing(['external_order_uuid' => 'uuid-should-not-leak']);
        $response->assertJsonMissingPath('external_response');
    }
}
