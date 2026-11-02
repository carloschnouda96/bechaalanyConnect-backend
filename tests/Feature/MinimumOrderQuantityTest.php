<?php

namespace Tests\Feature;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Minimum order size per variation (e.g. Jawaker sells from 10,000 coins).
 *
 * `min_quantity` is in the same unit as `orders.quantity` — the real coin count
 * for a Coin Recharge variation — and the supplier may report its own floor in
 * `external_qty_values.min`. ProductsVariation::minOrderQuantity() is the larger
 * of the two, and saveOrder refuses anything below it before a credit is debited.
 */
class MinimumOrderQuantityTest extends TestCase
{
    use CreatesCatalog;

    private function buyer(): User
    {
        return User::create([
            'username' => 'minqty_' . uniqid(),
            'email' => 'minqty_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 1000,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    /** Coin variation: $1 per 1,000 coins, minimum 10,000 coins. */
    private function coinVariation(): ProductsVariation
    {
        $variation = $this->createVariation(1.00, Product::TYPE_COIN);
        $variation->update(['unit_amount' => 1000, 'min_quantity' => 10000]);

        return $variation->refresh();
    }

    public function test_coin_minimum_is_in_coins(): void
    {
        $this->assertSame(10000, $this->coinVariation()->minOrderQuantity());
    }

    public function test_plain_variation_minimum_is_the_quantity(): void
    {
        $variation = $this->createVariation(2.00);
        $variation->update(['min_quantity' => 3]);

        $this->assertSame(3, $variation->refresh()->minOrderQuantity());
    }

    public function test_no_minimum_is_one(): void
    {
        $this->assertSame(1, $this->createVariation()->minOrderQuantity());
    }

    public function test_the_higher_of_admin_and_supplier_minimum_wins(): void
    {
        $variation = $this->coinVariation(); // admin: 10,000 coins

        $variation->update(['external_qty_values' => ['min' => 25000, 'max' => 3000000]]);
        $this->assertSame(25000, $variation->refresh()->minOrderQuantity());

        $variation->update(['external_qty_values' => ['min' => 1000, 'max' => 3000000]]);
        $this->assertSame(10000, $variation->refresh()->minOrderQuantity());
    }

    public function test_yassen_minimum_is_divided_by_the_qty_multiplier(): void
    {
        config(['services.yassen.qty_multiplier' => 1000]);

        $variation = $this->createVariation(1.00);
        $variation->product->update(['external_source' => 'yassen']);
        $variation->update(['external_qty_values' => ['min' => 10000]]);

        $this->assertSame(10, $variation->refresh()->minOrderQuantity());
    }

    public function test_order_below_the_minimum_is_refused_before_any_debit(): void
    {
        $variation = $this->coinVariation();
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 9999,
            'recipient_user' => 'player-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'below_min_quantity');
        $response->assertJsonPath('min_quantity', 10000);
        $this->assertStringContainsString('10,000', $response->json('message'));
        $this->assertSame(0, Order::where('users_id', $user->id)->count());
        $this->assertEquals(1000, (float) $user->refresh()->credits_balance);
    }

    public function test_order_at_the_minimum_is_accepted(): void
    {
        $variation = $this->coinVariation();
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 10000,
            'recipient_user' => 'player-1',
        ]);

        $response->assertSuccessful();
        $this->assertSame(1, Order::where('users_id', $user->id)->count());
        $this->assertEquals(990, (float) $user->refresh()->credits_balance);
    }

    public function test_variation_payload_exposes_the_minimum(): void
    {
        $payload = $this->coinVariation()->toArray();

        $this->assertSame(10000, $payload['min_order_quantity']);
    }

    public function test_admin_maximum_caps_the_order(): void
    {
        $variation = $this->coinVariation();
        $variation->update(['max_quantity' => 50000]);
        $variation->refresh();

        $this->assertSame(50000, $variation->maxOrderQuantity());
        $this->assertSame(50000, $variation->toArray()['max_order_quantity']);

        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 50001,
            'recipient_user' => 'player-1',
        ])->assertStatus(422)->assertJsonPath('code', 'above_max_quantity');

        $this->assertSame(0, Order::where('users_id', $user->id)->count());
        $this->assertEquals(1000, (float) $user->refresh()->credits_balance);
    }

    public function test_lower_supplier_maximum_wins_over_admin_maximum(): void
    {
        $variation = $this->createVariation(2.00);
        $variation->update(['max_quantity' => 50, 'external_qty_values' => ['min' => 1, 'max' => 10]]);

        $this->assertSame(10, $variation->refresh()->maxOrderQuantity());
    }
}
