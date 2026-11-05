<?php

namespace Tests\Feature;

use App\ApiKey;
use App\CreditLedgerEntry;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\UserType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class OrderMoneyGuardsTest extends TestCase
{
    use CreatesCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    private function user(float $balance, array $extra = []): User
    {
        return User::create($extra + [
            'username' => 'guard_' . uniqid(),
            'email' => 'guard_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    /** 0.07 * 3 is 0.21000000000000002 in floating point, so this customer was refused. */
    public function test_exactly_enough_credit_buys_the_order(): void
    {
        foreach ([[0.07, 3, 0.21], [1.10, 3, 3.30], [0.10, 3, 0.30], [19.99, 3, 59.97]] as [$price, $quantity, $balance]) {
            $user = $this->user($balance);
            Sanctum::actingAs($user);

            $this->postJson('/api/en/save-order', [
                'product_variation_id' => $this->createVariation($price)->id,
                'quantity' => $quantity,
            ])->assertCreated();

            $this->assertEquals(0.0, (float) $user->fresh()->credits_balance, "{$price} x {$quantity}");
        }
    }

    public function test_one_cent_short_is_still_refused(): void
    {
        $user = $this->user(0.20);
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $this->createVariation(0.07)->id,
            'quantity' => 3,
        ])->assertStatus(400)->assertJsonPath('code', 'insufficient_credits');
    }

    public function test_a_zero_priced_variation_cannot_be_ordered(): void
    {
        $user = $this->user(0);
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $this->createVariation(0.0)->id,
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 'product_unavailable');

        $this->assertSame(0, Order::where('users_id', $user->id)->count());
    }

    public function test_a_zero_tier_price_cannot_be_ordered(): void
    {
        $type = UserType::first() ?? UserType::create(['slug' => 'guard-' . uniqid()]);
        $variation = $this->createVariation(5.0);
        DB::table('product_price_variations')->insert([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'price' => 0,
        ]);

        $user = $this->user(0, ['user_types_id' => $type->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', ['product_variation_id' => $variation->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'product_unavailable');
    }

    public function test_the_usharez_zero_price_row_is_not_sold_and_its_siblings_are(): void
    {
        config([
            'services.usharez.enabled' => true,
            'services.usharez.token' => 'dummy',
            'services.usharez.base_url' => 'https://usharez.invalid',
            'services.usharez.gifts_enabled' => false,
            'services.usharez.lbp_per_usd' => 90000,
        ]);
        Http::fake(['usharez.invalid/api/quota/admin/stock*' => Http::response([
            'success' => true,
            'data' => [
                ['quota_size' => '89 GB', 'price' => 0],
                ['quota_size' => '11 GB', 'price' => 660000],
            ],
        ])]);

        Artisan::call('usharez:sync', ['--categories' => true]);
        DB::table('supplier_categories')->where('source', 'usharez')->update(['import_enabled' => 1, 'group_as_single_product' => 1]);
        Artisan::call('usharez:sync');

        $free = ProductsVariation::withoutGlobalScope('cms_draft_flag')->where('external_id', 'quota:89')->firstOrFail();
        $paid = ProductsVariation::withoutGlobalScope('cms_draft_flag')->where('external_id', 'quota:11')->firstOrFail();

        $this->assertFalse(ProductsVariation::sellable()->whereKey($free->id)->exists());
        $this->assertTrue(ProductsVariation::sellable()->whereKey($paid->id)->exists());
    }

    public function test_the_reseller_api_refuses_a_zero_price_without_dispatching_fulfillment(): void
    {
        $user = $this->user(0);
        $user->forceFill(['api_enabled' => 1])->save();
        $issued = ApiKey::issue($user, 'k');
        $plain = collect($issued)->first(fn ($v) => is_string($v));

        $variation = $this->createVariation(0.0, 2, true, 'available');

        $this->withHeaders(['Authorization' => 'Bearer ' . $plain])
            ->postJson('/api/v1/orders', ['variation_id' => $variation->id, 'quantity' => 1, 'client_order_id' => 'c-' . uniqid()])
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, CreditLedgerEntry::where('user_id', $user->id)->count());
    }
}
