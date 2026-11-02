<?php

namespace Tests\Feature\Reseller;

use App\ApiKey;
use App\CreditLedgerEntry;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\UserType;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The reseller API (/api/v1/*): other stores buying with an API key.
 *
 * Pins the properties an integration relies on and the ones that protect us:
 * keys stop working the moment they are revoked or API access is switched off,
 * the caller's tier price is the one quoted AND charged, a retried POST with the
 * same client_order_id never debits twice, supplier products are approved and
 * dispatched at once while manual products wait for an admin, and no cost or
 * supplier field ever reaches a reseller.
 */
class ResellerApiTest extends TestCase
{
    use CreatesCatalog;

    private function reseller(array $overrides = []): User
    {
        $user = User::create($overrides + [
            'username' => 'reseller_' . uniqid(),
            'email' => 'reseller_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        // Not mass-assignable on purpose: only the admin grants API access.
        $user->forceFill(['api_enabled' => $overrides['api_enabled'] ?? true])->save();

        return $user;
    }

    private function keyFor(User $user, ?array $ips = null): string
    {
        return ApiKey::issue($user, 'test', $ips)[1];
    }

    private function api(string $key): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $key);
    }

    private function supplierVariation(float $price = 5.00): ProductsVariation
    {
        $variation = $this->createVariation($price);
        $variation->forceFill(['external_source' => 'yassen', 'external_id' => 'svc-' . uniqid()])->save();

        return $variation->refresh();
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_requests_without_a_valid_key_are_refused(): void
    {
        $this->getJson('/api/v1/balance')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->api('bc_live_nope')->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_a_valid_key_reads_the_balance(): void
    {
        $user = $this->reseller(['credits_balance' => 42.5]);

        $this->api($this->keyFor($user))->getJson('/api/v1/balance')
            ->assertOk()
            ->assertJsonPath('data.balance', 42.5)
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_a_revoked_key_stops_working(): void
    {
        $user = $this->reseller();
        $plain = $this->keyFor($user);
        ApiKey::where('user_id', $user->id)->update(['revoked_at' => now()]);

        $this->api($plain)->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_switching_api_access_off_disables_every_key(): void
    {
        $user = $this->reseller();
        $plain = $this->keyFor($user);
        $user->forceFill(['api_enabled' => false])->save();

        $this->api($plain)->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_a_key_restricted_to_other_ips_is_refused(): void
    {
        $user = $this->reseller();

        $this->api($this->keyFor($user, ['203.0.113.9']))->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_the_key_is_stored_hashed(): void
    {
        $user = $this->reseller();
        $plain = $this->keyFor($user);

        $row = ApiKey::where('user_id', $user->id)->first();
        $this->assertNotSame($plain, $row->key_hash);
        $this->assertSame(hash('sha256', $plain), $row->key_hash);
        $this->assertArrayNotHasKey('key_hash', $row->toArray());
    }

    // ── Catalog ───────────────────────────────────────────────────────────────

    public function test_variation_is_quoted_at_the_callers_tier_price_without_cost_fields(): void
    {
        $type = UserType::create(['slug' => 'tier-' . uniqid()]);
        $variation = $this->createVariation(10.00);
        ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'price' => 7.00,
        ]);

        $user = $this->reseller(['user_types_id' => $type->id]);

        $data = $this->api($this->keyFor($user))->getJson('/api/v1/products/' . $variation->id)
            ->assertOk()
            ->json('data');

        $this->assertEquals(7.00, $data['price']);
        $this->assertSame(['id', 'product_id', 'product_name', 'name', 'type', 'description', 'price', 'pricing_unit', 'unit_label', 'min_quantity', 'max_quantity', 'required_fields'], array_keys($data));
    }

    public function test_product_list_contains_sellable_variations(): void
    {
        $variation = $this->createVariation(3.00);
        $hidden = $this->createVariation(3.00, 2, false);
        $user = $this->reseller();

        $ids = collect($this->api($this->keyFor($user))->getJson('/api/v1/products')->assertOk()->json('data'))
            ->pluck('variations')->flatten(1)->pluck('id');

        $this->assertTrue($ids->contains($variation->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    public function test_order_debits_through_the_ledger_and_waits_for_an_admin(): void
    {
        Queue::fake();
        $variation = $this->createVariation(4.00);
        $user = $this->reseller();

        $response = $this->api($this->keyFor($user))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 2,
            'client_order_id' => 'SHOP-1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.client_order_id', 'SHOP-1')
            ->assertJsonPath('data.total_price', 8);

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame('api', $order->source);
        $this->assertEquals(92, (float) $user->refresh()->credits_balance);
        $this->assertSame(1, CreditLedgerEntry::where('source_id', $order->id)->where('reason', CreditLedgerEntry::REASON_ORDER_PLACED)->count());
        Queue::assertNotPushed(FulfillSupplierOrderJob::class);
    }

    public function test_repeating_a_client_order_id_returns_the_same_order_without_a_second_debit(): void
    {
        Queue::fake();
        $variation = $this->createVariation(4.00);
        $user = $this->reseller();
        $key = $this->keyFor($user);
        $body = ['variation_id' => $variation->id, 'quantity' => 1, 'client_order_id' => 'SHOP-2'];

        $first = $this->api($key)->postJson('/api/v1/orders', $body)->assertStatus(201)->json('data.id');
        $second = $this->api($key)->postJson('/api/v1/orders', $body)->assertStatus(200)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::where('users_id', $user->id)->count());
        $this->assertEquals(96, (float) $user->refresh()->credits_balance);
    }

    public function test_supplier_order_is_approved_and_dispatched_at_once(): void
    {
        Queue::fake();
        $variation = $this->supplierVariation(5.00);
        $user = $this->reseller();

        $response = $this->api($this->keyFor($user))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 1,
            'client_order_id' => 'SHOP-3',
        ])->assertStatus(201);

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame(Order::STATUS_APPROVED, (int) $order->statuses_id);
        $this->assertSame(Order::STATUS_APPROVED, (int) $order->credits_applied_status);
        $this->assertEquals(95, (float) $user->refresh()->credits_balance);
        Queue::assertPushed(FulfillSupplierOrderJob::class);
    }

    public function test_storefront_supplier_order_still_waits_for_an_admin(): void
    {
        Queue::fake();
        $variation = $this->supplierVariation(5.00);
        $user = $this->reseller();
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 1,
        ])->assertStatus(201);

        $order = Order::where('users_id', $user->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->statuses_id);
        $this->assertSame('storefront', $order->source);
        Queue::assertNotPushed(FulfillSupplierOrderJob::class);
    }

    public function test_insufficient_credits_is_reported_and_nothing_is_charged(): void
    {
        $variation = $this->createVariation(500.00);
        $user = $this->reseller();

        $this->api($this->keyFor($user))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 1,
            'client_order_id' => 'SHOP-4',
        ])->assertStatus(400)
            ->assertJsonPath('error.code', 'insufficient_credits')
            ->assertJsonPath('error.missing', 400);

        $this->assertSame(0, Order::where('users_id', $user->id)->count());
    }

    public function test_client_order_id_is_required(): void
    {
        $variation = $this->createVariation(1.00);
        $user = $this->reseller();

        $this->api($this->keyFor($user))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_unverified_reseller_cannot_order(): void
    {
        $variation = $this->createVariation(1.00);
        $user = $this->reseller(['verification_statuses_id' => User::VERIFICATION_PENDING]);

        $this->api($this->keyFor($user))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 1,
            'client_order_id' => 'SHOP-5',
        ])->assertStatus(403)->assertJsonPath('error.code', 'kyc_required');
    }

    public function test_another_resellers_order_is_not_visible(): void
    {
        Queue::fake();
        $variation = $this->createVariation(1.00);
        $owner = $this->reseller();
        $id = $this->api($this->keyFor($owner))->postJson('/api/v1/orders', [
            'variation_id' => $variation->id,
            'quantity' => 1,
            'client_order_id' => 'SHOP-6',
        ])->json('data.id');

        $this->api($this->keyFor($this->reseller()))->getJson('/api/v1/orders/' . $id)->assertStatus(404);
        $this->api($this->keyFor($owner))->getJson('/api/v1/orders?client_order_id=SHOP-6')
            ->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_codes_are_returned_once_completed(): void
    {
        $user = $this->reseller();
        $order = Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation()->id,
            'quantity' => 1,
            'total_price' => 5,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'code' => '<ul><li>AAAA-1111</li><li>BBBB-2222</li></ul>',
        ]);

        $this->api($this->keyFor($user))->getJson('/api/v1/orders/' . $order->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.codes', ['AAAA-1111', 'BBBB-2222']);
    }

    // ── Key management (storefront) ───────────────────────────────────────────

    public function test_customer_without_api_access_cannot_create_a_key(): void
    {
        Sanctum::actingAs($this->reseller(['api_enabled' => false]));

        $this->postJson('/api/user/api-keys', ['name' => 'My shop'])
            ->assertStatus(403)->assertJsonPath('code', 'api_not_enabled');
    }

    public function test_customer_creates_lists_and_revokes_a_key(): void
    {
        $user = $this->reseller();
        Sanctum::actingAs($user);

        $plain = $this->postJson('/api/user/api-keys', ['name' => 'My shop'])->assertStatus(201)->json('key');
        $this->assertStringStartsWith(ApiKey::PREFIX, $plain);

        $keys = $this->getJson('/api/user/api-keys')->assertOk()->json('keys');
        $this->assertCount(1, $keys);
        $this->assertArrayNotHasKey('key_hash', $keys[0]);

        $this->deleteJson('/api/user/api-keys/' . $keys[0]['id'])->assertOk();
        $this->assertCount(0, $this->getJson('/api/user/api-keys')->json('keys'));
    }
}
