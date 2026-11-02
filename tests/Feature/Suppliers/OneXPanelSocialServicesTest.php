<?php

namespace Tests\Feature\Suppliers;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\OneXPanelConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\SupplierCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * 1xpanel `Default` services are Social Media Services (product_type 5): one
 * product per category with the services as its dropdown, priced per 1,000 like
 * the panel quotes them, with the details the panel publishes on its /services
 * page. Before this, a $0.0029 unit cost was stored as a $0.00 per-unit price —
 * every order was free — and the 100-unit cap made the panel's own minimums
 * (often 500+) impossible to order.
 */
class OneXPanelSocialServicesTest extends TestCase
{
    use CreatesCatalog;

    private const BASE = 'https://1xpanel.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.1xpanel.base_url' => self::BASE,
            'services.1xpanel.key' => 'test-key',
            'services.1xpanel.enabled' => true,
            'services.1xpanel.scrape_details' => true,
            'services.1xpanel.group_categories_by_default' => true,
        ]);
        Cache::forget('perfectpanel-details:1xpanel');
    }

    private function fakePanel(int $pageStatus = 200): void
    {
        Http::fake([
            self::BASE . '/api/v2*' => Http::response([
                [
                    'service' => 817, 'name' => 'Mix 1XPanel 🚩Premium Members Telegram - 365 Days',
                    'type' => 'Default', 'rate' => '3.05', 'min' => 500, 'max' => 15000,
                    'dripfeed' => false, 'refill' => true, 'cancel' => true,
                    'category' => 'Telegram - Premium Members',
                ],
                [
                    'service' => 5153, 'name' => 'Telegram Post Views - USA',
                    'type' => 'Default', 'rate' => '0.40', 'min' => 10, 'max' => 100000,
                    'dripfeed' => false, 'refill' => false, 'cancel' => false,
                    'category' => 'Telegram - Premium Members',
                ],
            ]),
            self::BASE . '/services' => Http::response(
                $pageStatus === 200 ? PerfectPanelServicePageTest::fixture() : 'down',
                $pageStatus
            ),
        ]);
    }

    private function sync(): void
    {
        $connector = new OneXPanelConnector();
        (new SupplierCatalogSync())->sync($connector, true);
        SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', '1xpanel')
            ->update(['import_enabled' => true]);
        (new SupplierCatalogSync())->sync($connector);
    }

    private function variation(string $externalId): ProductsVariation
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->where('external_id', $externalId)
            ->whereHas('product', fn ($q) => $q->withoutGlobalScope('cms_draft_flag')->where('external_source', '1xpanel'))
            ->firstOrFail();
    }

    public function test_a_category_imports_as_one_social_media_product_priced_per_thousand(): void
    {
        $this->fakePanel();
        $this->sync();

        $category = SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', '1xpanel')->firstOrFail();
        $this->assertTrue((bool) $category->group_as_single_product, 'new 1xpanel categories are grouped by default');

        $members = $this->variation('817');
        $views = $this->variation('5153');
        $this->assertSame($members->product_id, $views->product_id, 'both services are entries of one product');
        $this->assertSame(Product::TYPE_SOCIAL, (int) $members->product->product_type_id);
        $this->assertSame('Premium Members Telegram - 365 Days', $members->name, 'supplier branding stripped');

        // 3.05 per 1,000 → cost 0.00305/unit; price is per 1,000, never $0.00.
        $this->assertSame(1000, (int) $members->unit_amount);
        $this->assertSame(
            ProductsVariation::computeSellingPrice(3.05, $members->effectiveProfitPercentage()),
            (float) $members->price
        );
        $this->assertTrue($members->isProrated());
        $this->assertSame(500, $members->minOrderQuantity());
        $this->assertSame(15000, $members->maxOrderQuantity(), 'the 100-unit cap does not apply');

        $this->assertSame('Premium Members', $members->external_details['quality']);
        $this->assertSame('8 minutes', $members->external_details['average_time']);
        $this->assertTrue($members->external_details['refill_available']);
        $this->assertSame('Post Link', $views->external_details['link']);
        $this->assertNull($views->external_details['average_time']);
    }

    public function test_details_ship_on_the_public_product_payload_but_cost_does_not(): void
    {
        $this->fakePanel();
        $this->sync();

        $payload = $this->variation('817')->toArray();

        $this->assertArrayHasKey('external_details', $payload);
        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('external_price', $payload);
    }

    public function test_an_unreadable_services_page_keeps_the_last_good_details(): void
    {
        $this->fakePanel();
        $this->sync();
        $this->assertSame('Fast', $this->variation('817')->external_details['speed']);

        Cache::forget('perfectpanel-details:1xpanel');
        $this->fakePanel(503);
        (new SupplierCatalogSync())->sync(new OneXPanelConnector());

        $this->assertSame('Fast', $this->variation('817')->external_details['speed']);
    }

    public function test_package_services_stay_direct_recharge(): void
    {
        $connector = new class extends OneXPanelConnector {
            public function typeFor(array $raw): int
            {
                return $this->productTypeId($raw);
            }
        };

        $this->assertSame(Product::TYPE_USER_ID, $connector->typeFor(['type' => 'Package']));
        $this->assertSame(Product::TYPE_SOCIAL, $connector->typeFor(['type' => 'Default']));
    }

    public function test_a_social_order_charges_the_prorated_total_and_needs_a_link(): void
    {
        $variation = $this->createVariation(3.66, Product::TYPE_SOCIAL);
        $variation->update(['unit_amount' => 1000, 'external_qty_values' => ['min' => 500, 'max' => 15000]]);

        $user = User::create([
            'username' => 'smm_' . uniqid(),
            'email' => 'smm_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 750,
        ])->assertStatus(422)->assertJsonPath('code', 'recipient_user_required');

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 499,
            'recipient_user' => 'https://t.me/example',
        ])->assertStatus(422)->assertJsonPath('code', 'below_min_quantity');

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 750,
            'recipient_user' => 'https://t.me/example',
        ])->assertSuccessful();

        $order = Order::where('users_id', $user->id)->firstOrFail();
        $this->assertSame(750, (int) $order->quantity);
        // 750 × $3.66 / 1,000 = 2.745 → rounded UP to 2.75.
        $this->assertEquals(2.75, (float) $order->total_price);
        $this->assertEquals(97.25, (float) $user->refresh()->credits_balance);
    }

    public function test_the_migration_reprices_an_existing_free_1xpanel_row(): void
    {
        $variation = $this->createVariation(0.00, Product::TYPE_USER_ID);
        $variation->product->update(['external_source' => '1xpanel', 'external_id' => '5153']);
        ProductsVariation::applyingSystemPricing(fn () => $variation->update([
            'external_type' => 'Default',
            'cost_price' => 0.0029714,
            'external_price' => 0.0029714,
            'profit_percentage' => 20,
            'price' => 0.00,
        ]));

        (require database_path('migrations/2026_10_12_000001_social_media_services_for_perfect_panel.php'))->up();

        $variation->refresh();
        $this->assertSame(Product::TYPE_SOCIAL, (int) $variation->product->fresh()->product_type_id);
        // 0.0029714 × 1,000 × 1.2 = 3.57 per 1,000.
        $this->assertSame(1000, (int) $variation->unit_amount);
        $this->assertEquals(3.57, (float) $variation->price);
    }
}
