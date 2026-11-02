<?php

namespace Tests\Feature;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\SupplierCategory;
use App\UserType;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Coin Recharge orders carry the REAL coin count the customer typed in
 * `quantity`; `price` is the price of `unit_amount` coins (e.g. $0.13 per 1,000
 * Jawaker), so the total is prorated and rounded up to the cent.
 *
 * Yassen prices these per coin ($0.000105) and takes `qty` = coins. Stored
 * per coin in decimal(12,2), the sync used to publish them at $0.00.
 */
class CoinRechargeOrderTest extends TestCase
{
    use CreatesCatalog;

    private function buyer(?int $userTypeId = null): User
    {
        return User::create([
            'username' => 'coin_' . uniqid(),
            'email' => 'coin_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'user_types_id' => $userTypeId,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    /** $0.13 per 1,000 coins. */
    private function coinVariation(array $attributes = []): ProductsVariation
    {
        $variation = $this->createVariation(0.13, Product::TYPE_COIN);
        $variation->update(array_merge(['unit_amount' => 1000], $attributes));

        return $variation->refresh();
    }

    private function order(ProductsVariation $variation, int $coins, ?string $player = 'player-1')
    {
        return $this->postJson('/api/en/save-order', array_filter([
            'product_variation_id' => $variation->id,
            'quantity' => $coins,
            'recipient_user' => $player,
        ], fn ($v) => $v !== null));
    }

    public function test_coin_total_is_prorated_and_rounded_up_to_the_cent(): void
    {
        $this->assertSame(1.30, ProductsVariation::coinTotal(0.13, 10000, 1000));
        $this->assertSame(0.01, ProductsVariation::coinTotal(0.13, 1, 1000));
        $this->assertSame(0.33, ProductsVariation::coinTotal(0.13, 2500, 1000)); // 0.325 → up
        $this->assertSame(5.00, ProductsVariation::coinTotal(5.00, 1, 1));
    }

    public function test_order_stores_the_real_coin_count_and_charges_the_prorated_total(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $response = $this->order($this->coinVariation(), 25000);

        $response->assertSuccessful();
        $order = Order::where('users_id', $user->id)->firstOrFail();
        $this->assertSame(25000, (int) $order->quantity);
        $this->assertEquals(3.25, (float) $order->total_price);
        $this->assertSame(25000, $order->coins);
        $this->assertEquals(96.75, (float) $user->refresh()->credits_balance);
    }

    public function test_player_id_is_required_for_coin_products(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $response = $this->order($this->coinVariation(), 10000, null);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'recipient_user_required');
        $this->assertSame(0, Order::where('users_id', $user->id)->count());
    }

    public function test_coins_above_the_supplier_max_are_refused(): void
    {
        Sanctum::actingAs($this->buyer());

        $variation = $this->coinVariation(['external_qty_values' => ['min' => 10000, 'max' => 50000]]);
        $this->assertSame(50000, $variation->maxOrderQuantity());

        $response = $this->order($variation, 50001);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'above_max_quantity');
    }

    public function test_non_coin_products_keep_the_100_unit_cap(): void
    {
        Sanctum::actingAs($this->buyer());
        $variation = $this->createVariation(0.10);

        $this->assertSame(100, $variation->maxOrderQuantity());

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 101,
        ])->assertStatus(422)->assertJsonPath('code', 'above_max_quantity');
    }

    public function test_tier_price_is_prorated_too(): void
    {
        $type = UserType::create(['slug' => 'coin-tier-' . uniqid()]);
        $variation = $this->coinVariation();
        ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'price' => 0.10,
        ]);

        $user = $this->buyer($type->id);
        Sanctum::actingAs($user);

        $this->order($variation, 10000)->assertSuccessful();

        $this->assertEquals(1.00, (float) Order::where('users_id', $user->id)->value('total_price'));
    }

    public function test_cost_is_scaled_by_the_pricing_unit(): void
    {
        $variation = $this->coinVariation(['cost_price' => 0.000105, 'profit_percentage' => 20]);

        // 0.000105 × 1,000 × 1.2 = 0.126 → 0.13, never 0.00.
        $this->assertSame(0.13, $variation->priceFromCost());
    }

    public function test_coin_pricing_unit_is_the_power_of_ten_that_reaches_one_dollar(): void
    {
        $this->assertSame(10000, SupplierCatalogSync::coinPricingUnit(0.000105)); // Jawaker
        $this->assertSame(1000, SupplierCatalogSync::coinPricingUnit(0.00172));   // SoulChill
        $this->assertSame(10, SupplierCatalogSync::coinPricingUnit(0.1005));
        $this->assertSame(1, SupplierCatalogSync::coinPricingUnit(0.5 * 2));
        $this->assertSame(1, SupplierCatalogSync::coinPricingUnit(4.0));
    }

    public function test_the_sync_prices_a_per_coin_supplier_row_per_pricing_unit(): void
    {
        $connector = new FakeCoinConnector('test-coin', [new SupplierProduct(
            externalId: '97',
            name: 'JAWAKER',
            categoryExternalId: 'coin-cat',
            categoryName: 'Coins',
            categoryImage: null,
            unitCost: 0.000105,
            available: true,
            productTypeId: Product::TYPE_COIN,
            qtyValues: ['min' => '10000', 'max' => '3000000'],
            externalType: 'amount',
        )]);

        (new SupplierCatalogSync())->sync($connector, true);
        $category = SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', 'test-coin')->where('external_id', 'coin-cat')->firstOrFail();
        $category->import_enabled = true;
        $category->group_as_single_product = true;
        $category->save();
        (new SupplierCatalogSync())->sync($connector);

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', '97')->firstOrFail();

        $this->assertSame(10000, (int) $variation->unit_amount);
        $this->assertSame(
            ProductsVariation::computeSellingPrice(1.05, $variation->effectiveProfitPercentage()),
            (float) $variation->price
        );
        $this->assertGreaterThan(0, (float) $variation->price);
        $this->assertSame(10000, $variation->minOrderQuantity());
        $this->assertSame(3000000, $variation->maxOrderQuantity());
    }

    public function test_the_migration_fixes_a_free_yassen_coin_row(): void
    {
        $variation = $this->createVariation(0.00, Product::TYPE_USER_ID);
        $variation->product->update(['external_source' => 'yassen', 'external_id' => 'grp']);
        ProductsVariation::applyingSystemPricing(fn () => $variation->update([
            'external_type' => 'amount',
            'cost_price' => 0.000105,
            'external_price' => 0.000105,
            'profit_percentage' => 20,
            'price' => 0.00,
        ]));

        (require database_path('migrations/2026_10_09_000001_fix_yassen_coin_variations.php'))->up();

        $variation->refresh();
        $this->assertSame(Product::TYPE_COIN, (int) $variation->product->fresh()->product_type_id);
        // 0.000105 × 10,000 × 1.2 = 1.26 per 10,000 coins.
        $this->assertSame(10000, (int) $variation->unit_amount);
        $this->assertEquals(1.26, (float) $variation->price);
        $this->assertFalse((bool) $variation->manual_price);
    }
}

/** Minimal in-test supplier. Only fetchCatalog()/key() matter to the sync engine. */
class FakeCoinConnector implements SupplierConnector
{
    public function __construct(private string $key, public array $catalog = [])
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetchCatalog(): array
    {
        return $this->catalog;
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        return new SupplierOrderResult(externalOrderId: 'fake', status: SupplierOrderResult::COMPLETED, raw: []);
    }

    public function checkOrder(Order $order): SupplierOrderResult
    {
        return new SupplierOrderResult(externalOrderId: 'fake', status: SupplierOrderResult::COMPLETED, raw: []);
    }

    public function balance(): ?float
    {
        return null;
    }
}
