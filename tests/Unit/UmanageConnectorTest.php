<?php

namespace Tests\Unit;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\UmanageConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use App\Services\Umanage\UmanageBusinessException;
use App\Services\Umanage\UmanageClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit coverage for the U-Manage connector. Faked HTTP, no database (the LBP→USD
 * rate is injected rather than read from fixed_settings), so it's safe against
 * the real MySQL test DB.
 *
 * The store id is pinned in setUp so most tests skip the GET /stores discovery
 * round trip; discovery itself has dedicated tests.
 */
class UmanageConnectorTest extends TestCase
{
    private const RATE = 89500.0;
    private const STORE = 24;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.umanage.base_url' => 'https://api.umanageapp.uk/api/v1/external',
            'services.umanage.key' => 'pk_live_test',
            'services.umanage.secret' => 'sk_live_test',
            'services.umanage.store_id' => self::STORE,
            'services.umanage.enabled' => true,
            'services.umanage.bundles_enabled' => true,
            'services.umanage.sms_enabled' => true,
            'services.umanage.telecom_enabled' => true,
            'services.umanage.lbp_per_usd' => self::RATE,
        ]);
    }

    private function connector(?float $rate = self::RATE): UmanageConnector
    {
        return new UmanageConnector(new UmanageClient(), $rate);
    }

    private function forbidden(): \Illuminate\Http\Client\ResponseSequence|\GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Nope']], 403);
    }

    // ---------------------------------------------------------------- store scope

    public function test_pinned_store_id_skips_discovery(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'bundles' => []], 200)]);

        $this->connector()->fetchCatalog();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/external/stores'));
    }

    public function test_store_id_is_discovered_when_not_pinned(): void
    {
        config(['services.umanage.store_id' => null]);

        Http::fake([
            '*external/stores' => Http::response(['success' => true, 'stores' => [
                ['store_id' => 77, 'store_name' => 'Main'],
            ]], 200),
            '*' => Http::response(['success' => true, 'bundles' => []], 200),
        ]);

        $this->connector()->fetchCatalog();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/stores/77/stock'));
    }

    /** No linked store is a retryable failure, not an empty catalog (which deactivates). */
    public function test_no_linked_store_throws_rather_than_emptying_the_catalog(): void
    {
        config(['services.umanage.store_id' => null]);
        Http::fake(['*' => Http::response(['success' => true, 'stores' => []], 200)]);

        $this->expectException(SupplierApiException::class);
        $this->connector()->fetchCatalog();
    }

    // -------------------------------------------------------------------- catalog

    public function test_bundle_stock_maps_lbp_to_usd_and_prefixes_family(): void
    {
        Http::fake([
            '*stores/24/stock*' => Http::response(['success' => true, 'bundles' => [
                ['bundle_id' => 1, 'bundle_size' => 1, 'bundle_type' => 'closed', 'bundle_days' => 27, 'price_lbp' => 150000, 'is_available' => true],
                ['bundle_id' => 2, 'bundle_size' => 2, 'bundle_type' => 'admin', 'bundle_days' => null, 'price_lbp' => 280000, 'is_available' => false],
            ]], 200),
            '*' => $this->forbidden(),
        ]);

        $catalog = $this->connector()->fetchCatalog();

        $this->assertCount(2, $catalog);
        $this->assertSame('bundle:1', $catalog[0]->externalId);
        $this->assertSame('1 GB Data Bundle — 27 days', $catalog[0]->name);
        $this->assertSame(round(150000 / self::RATE, 4), $catalog[0]->unitCost);
        $this->assertSame(UmanageConnector::CATEGORY_BUNDLE, $catalog[0]->categoryExternalId);
        $this->assertSame(3, $catalog[0]->productTypeId);
        $this->assertTrue($catalog[0]->available);

        $this->assertSame('2 GB Data Bundle', $catalog[1]->name);   // no days suffix
        $this->assertFalse($catalog[1]->available);
    }

    public function test_sms_products_split_into_credit_and_gift_families(): void
    {
        Http::fake([
            '*sms-products*' => Http::response(['success' => true, 'has_sms_service' => true, 'credits' => [
                ['carrier_type' => 'alfa', 'min_amount_usd' => 0.5, 'max_amount_usd' => 50, 'increment_usd' => 0.5, 'pricing' => [
                    ['amount_usd' => 0.5, 'price_lbp' => 45000],
                    ['amount_usd' => 1, 'price_lbp' => 90000],
                ]],
            ], 'gifts' => [
                ['product_code' => 'MI7', 'name' => 'Mobile Internet 7 GB', 'price_usd' => 9, 'validity_days' => 30, 'carrier_type' => 'alfa', 'price_lbp' => 810000],
            ]], 200),
            '*' => $this->forbidden(),
        ]);

        $catalog = $this->connector()->fetchCatalog();
        $byId = collect($catalog)->keyBy->externalId;

        $this->assertCount(3, $catalog);

        $half = $byId['sms-credit:alfa:0.5'];
        $this->assertSame('$0.5 Alfa Credit', $half->name);
        $this->assertSame(round(45000 / self::RATE, 4), $half->unitCost);
        $this->assertSame(UmanageConnector::CATEGORY_SMS_CREDIT, $half->categoryExternalId);

        // 1.0 must render as "1", not "1.00" — external_id has to stay stable.
        $this->assertTrue($byId->has('sms-credit:alfa:1'));

        $gift = $byId['sms-gift:alfa:MI7'];
        $this->assertSame('Mobile Internet 7 GB — 30 days', $gift->name);
        $this->assertSame(round(810000 / self::RATE, 4), $gift->unitCost);
        $this->assertSame(3, $gift->productTypeId);
    }

    /**
     * SMS rows carry a face value alongside the LBP price we pay, and margin is
     * never negative — so the smallest price_lbp/face_usd ratio is a hard ceiling
     * on a sane divisor. Exceeding it means deriving a cost below the value being
     * delivered, i.e. selling at a guaranteed loss.
     *
     * Figures below are the real live payload (touch $3 = 276000 is the tightest
     * ratio at 92,000).
     */
    private function smsRateFixture(): array
    {
        return ['success' => true, 'has_sms_service' => true,
            'credits' => [
                ['carrier_type' => 'alfa', 'pricing' => [['amount_usd' => 0.5, 'price_lbp' => 75000], ['amount_usd' => 3, 'price_lbp' => 300000]]],
                ['carrier_type' => 'touch', 'pricing' => [['amount_usd' => 3, 'price_lbp' => 276000]]],
            ],
            'gifts' => [['product_code' => 'MI7', 'name' => 'MI7', 'price_usd' => 9, 'price_lbp' => 855000, 'carrier_type' => 'alfa']],
        ];
    }

    public function test_rate_above_the_face_value_ceiling_is_warned_about(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        Http::fake(['*sms-products*' => Http::response($this->smsRateFixture(), 200), '*' => $this->forbidden()]);

        // 95,000 exceeds the tightest ratio (276000/3 = 92,000).
        (new UmanageConnector(new UmanageClient(), 95000.0))->fetchCatalog();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'rate is too high')
                && (int) $context['max_safe_lbp_per_usd'] === 92000)
            ->once();
    }

    public function test_rate_within_the_face_value_ceiling_does_not_warn(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        // Every family answers cleanly, so the rate check is the only thing that
        // could log a warning here.
        Http::fake([
            '*sms-products*' => Http::response($this->smsRateFixture(), 200),
            '*stores/24/stock*' => Http::response(['success' => true, 'bundles' => [
                ['bundle_id' => 1, 'bundle_size' => 1, 'price_lbp' => 150000, 'is_available' => true],
            ]], 200),
            '*telecom-products*' => Http::response(['success' => true, 'products' => []], 200),
        ]);

        // The configured default sits safely under the 92,000 ceiling.
        (new UmanageConnector(new UmanageClient(), 89500.0))->fetchCatalog();

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'rate sanity check passed'))
            ->once();
    }

    public function test_store_without_sms_service_is_skipped(): void
    {
        Http::fake([
            '*sms-products*' => Http::response(['success' => true, 'has_sms_service' => false, 'credits' => [], 'gifts' => []], 200),
            '*' => $this->forbidden(),
        ]);

        $this->assertSame([], $this->connector()->fetchCatalog());
    }

    /** A voucher product that also supports direct recharge yields TWO rows. */
    public function test_telecom_product_emits_voucher_and_recharge_rows(): void
    {
        Http::fake([
            '*telecom-products*' => Http::response(['success' => true, 'products' => [
                ['product_id' => 412, 'name' => 'Alfa Recharge $5', 'brand' => 'Alfa', 'product_type' => 'voucher', 'category' => 'Recharge Cards', 'supports_direct_recharge' => true, 'supports_plans' => false, 'required_fields' => null, 'price_lbp' => 480000],
            ]], 200),
            '*' => $this->forbidden(),
        ]);

        $catalog = $this->connector()->fetchCatalog();
        $byId = collect($catalog)->keyBy->externalId;

        $this->assertCount(2, $catalog);

        $voucher = $byId['telecom-voucher:412'];
        $this->assertSame('Alfa Recharge $5', $voucher->name);
        $this->assertSame(2, $voucher->productTypeId);          // Recharge By Code — no recipient
        $this->assertSame(round(480000 / self::RATE, 4), $voucher->unitCost);

        $recharge = $byId['telecom-recharge:412'];
        $this->assertSame('Alfa Recharge $5 — Direct Recharge', $recharge->name);
        $this->assertSame(3, $recharge->productTypeId);          // phone recipient
    }

    public function test_plan_and_required_field_products_are_excluded(): void
    {
        Http::fake([
            '*telecom-products*' => Http::response(['success' => true, 'products' => [
                ['product_id' => 530, 'name' => 'Plan product', 'product_type' => 'plan', 'supports_plans' => true, 'price_lbp' => 480000],
                ['product_id' => 531, 'name' => 'Needs fields', 'product_type' => 'voucher', 'required_fields' => ['iban'], 'price_lbp' => 480000],
                ['product_id' => 532, 'name' => 'Fine', 'product_type' => 'voucher', 'supports_plans' => false, 'required_fields' => null, 'price_lbp' => 100000],
            ]], 200),
            '*' => $this->forbidden(),
        ]);

        $catalog = $this->connector()->fetchCatalog();

        $this->assertCount(1, $catalog);
        $this->assertSame('telecom-voucher:532', $catalog[0]->externalId);
    }

    /** One family's outage must not deactivate another's products. */
    public function test_partial_failure_reports_only_reachable_scopes(): void
    {
        Http::fake([
            '*stores/24/stock*' => Http::response(['success' => true, 'bundles' => [
                ['bundle_id' => 1, 'bundle_size' => 1, 'price_lbp' => 150000, 'is_available' => true],
            ]], 200),
            '*' => $this->forbidden(),
        ]);

        $connector = $this->connector();
        $catalog = $connector->fetchCatalog();

        $this->assertCount(1, $catalog);
        $this->assertSame(['bundle:'], $connector->catalogScopes());
    }

    public function test_all_families_failing_throws(): void
    {
        Http::fake(['*' => $this->forbidden()]);

        $this->expectException(SupplierApiException::class);
        $this->connector()->fetchCatalog();
    }

    public function test_empty_bundle_stock_does_not_vouch_for_the_family(): void
    {
        Http::fake([
            '*stores/24/stock*' => Http::response(['success' => true, 'bundles' => []], 200),
            '*sms-products*' => Http::response(['success' => true, 'has_sms_service' => true, 'credits' => [], 'gifts' => []], 200),
            '*' => $this->forbidden(),
        ]);

        $connector = $this->connector();
        $connector->fetchCatalog();

        $this->assertSame([], $connector->catalogScopes());
    }

    public function test_zero_rate_makes_connector_unconfigured(): void
    {
        $connector = $this->connector(0.0);

        $this->assertFalse($connector->isConfigured());
        $this->expectException(SupplierApiException::class);
        $connector->fetchCatalog();
    }

    // ------------------------------------------------------------------- ordering

    public function test_bundle_order_posts_phone_and_prefixes_the_order_id(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => [
            'order_id' => 98765, 'status' => 'processing',
        ]], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70 123 456', 'quantity' => 1]),
            $this->variation('bundle:1')
        );

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);   // async
        $this->assertSame('bundle:98765', $result->externalOrderId);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/stores/24/orders')
                && $r['bundle_id'] === '1'
                && $r['secondary_number'] === '70123456'
                && $r->hasHeader('X-API-Key', 'pk_live_test')
                && $r->hasHeader('X-API-Secret', 'sk_live_test');
        });
    }

    public function test_sms_credit_order_sends_type_code_and_carrier(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => [
            'order_id' => 12345, 'status' => 'pending',
        ]], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
            $this->variation('sms-credit:alfa:5.5')
        );

        $this->assertSame('sms-credit:12345', $result->externalOrderId);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/stores/24/sms-orders')
                && $r['product_type'] === 'credit'
                && $r['product_code'] === '5.5'
                && $r['carrier_type'] === 'alfa'
                && $r['recipient_phone'] === '70123456';
        });
    }

    public function test_sms_gift_order_sends_gift_type(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => ['order_id' => 1, 'status' => 'pending']], 200)]);

        $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
            $this->variation('sms-gift:alfa:MI7')
        );

        Http::assertSent(fn ($r) => $r['product_type'] === 'gift' && $r['product_code'] === 'MI7');
    }

    /**
     * The voucher code must land in orders.code as HTML with <li> elements —
     * order-codes.tsx parses it with querySelectorAll('li') and renders NOTHING
     * when there are none.
     */
    public function test_telecom_voucher_writes_an_li_wrapped_code_to_the_order(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => [
            'order_id' => 98801,
            'status' => 'completed',
            'purchase_mode' => 'voucher',
            'voucher' => ['code' => '1234-5678-9012-3456', 'serial' => '9988776655', 'expiry_date' => '2026-12-31'],
        ]], 201)]);

        $order = $this->order(['quantity' => 1]);
        $result = $this->connector()->placeOrder($order, $this->variation('telecom-voucher:412'));

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        $this->assertSame('telecom-voucher:98801', $result->externalOrderId);

        $this->assertStringContainsString('<li>1234-5678-9012-3456</li>', $order->code);
        $this->assertStringContainsString('Serial: 9988776655', $order->code);

        // Prove the storefront parser would actually find the code.
        $this->assertSame(1, substr_count($order->code, '<li>'));

        // A voucher purchase carries no recipient.
        Http::assertSent(fn ($r) => $r['purchase_mode'] === 'voucher' && !isset($r['recipient_phone']));
    }

    public function test_telecom_recharge_sends_recipient_phone(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => ['order_id' => 5, 'status' => 'completed']], 201)]);

        $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
            $this->variation('telecom-recharge:412')
        );

        Http::assertSent(fn ($r) => $r['purchase_mode'] === 'recharge' && $r['recipient_phone'] === '70123456');
    }

    /** Telecom failures arrive as HTTP 200 success:false, carrying a real order id. */
    public function test_telecom_failure_on_http_200_returns_failed_and_keeps_the_order_id(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'order' => ['order_id' => 98802, 'status' => 'failed', 'refunded' => true],
            'error' => ['code' => 'TELECOM_ORDER_FAILED', 'message' => 'Purchase failed. Your wallet has been refunded.'],
        ], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['quantity' => 1]),
            $this->variation('telecom-voucher:412')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertSame('telecom-voucher:98802', $result->externalOrderId);
        $this->assertStringContainsString('TELECOM_ORDER_FAILED', $result->raw['umanage_error']);
    }

    public function test_insufficient_balance_returns_failed(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => 'Insufficient wallet balance'],
        ], 400)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
            $this->variation('bundle:1')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertStringContainsString('Insufficient wallet balance', $result->raw['umanage_error']);
    }

    /** 429 is the documented rate-limit code — retry, never refund. */
    public function test_rate_limit_and_server_errors_throw_rather_than_refunding(): void
    {
        foreach ([429, 503, 401] as $status) {
            Http::fake(['*' => Http::response(['error' => ['code' => 'X', 'message' => 'nope']], $status)]);

            try {
                $this->connector()->placeOrder(
                    $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
                    $this->variation('bundle:1')
                );
                $this->fail("Expected HTTP {$status} to throw.");
            } catch (SupplierApiException $e) {
                $this->assertNotInstanceOf(UmanageBusinessException::class, $e, "HTTP {$status} must be retryable");
            }
        }
    }

    public function test_indeterminate_post_returns_pending_with_prefixed_synthetic_id(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1, 'external_order_uuid' => 'uuid-7']),
            $this->variation('bundle:1')
        );

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertSame('bundle:umanage-uuid-7', $result->externalOrderId);
        $this->assertTrue($result->raw['umanage_indeterminate']);
    }

    /** No U-Manage endpoint takes a quantity, so >1 must never reach the API. */
    public function test_quantity_above_one_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 2]),
            $this->variation('bundle:1')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_missing_phone_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '', 'quantity' => 1]),
            $this->variation('bundle:1')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_unrecognised_external_id_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '70123456', 'quantity' => 1]),
            $this->variation('legacy-42')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------- polling

    /**
     * checkOrder gets only the Order, so the family must be recovered from the
     * external_order_id prefix to pick the right status endpoint.
     * @dataProvider pollProvider
     */
    public function test_check_order_routes_to_the_right_endpoint(string $externalOrderId, string $expectedPath): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => ['status' => 'completed']], 200)]);

        $order = new Order();
        $order->external_order_id = $externalOrderId;
        $order->external_status = SupplierOrderResult::PENDING;

        $result = $this->connector()->checkOrder($order);

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        $this->assertSame($externalOrderId, $result->externalOrderId);
        Http::assertSent(fn ($r) => str_contains($r->url(), $expectedPath));
    }

    public static function pollProvider(): array
    {
        return [
            'bundle' => ['bundle:98765', '/stores/24/orders/98765'],
            'sms credit' => ['sms-credit:12345', '/stores/24/sms-orders/12345'],
            'sms gift' => ['sms-gift:12346', '/stores/24/sms-orders/12346'],
            'telecom voucher' => ['telecom-voucher:98801', '/stores/24/telecom-orders/98801'],
            'telecom recharge' => ['telecom-recharge:98802', '/stores/24/telecom-orders/98802'],
        ];
    }

    public function test_check_order_maps_failed_status(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => ['status' => 'failed']], 200)]);

        $order = new Order();
        $order->external_order_id = 'bundle:1';
        $order->external_status = SupplierOrderResult::PENDING;

        $this->assertSame(SupplierOrderResult::FAILED, $this->connector()->checkOrder($order)->status);
    }

    /** A synthetic id from an indeterminate placement isn't pollable. */
    public function test_check_order_on_synthetic_id_makes_no_http_call(): void
    {
        Http::fake();

        $order = new Order();
        $order->external_order_id = 'bundle:umanage-uuid-7';
        $order->external_status = SupplierOrderResult::PENDING;

        $result = $this->connector()->checkOrder($order);

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        Http::assertNothingSent();
    }

    /** A 404/403 on lookup must not be read as "the order failed". */
    public function test_check_order_lookup_rejection_keeps_stored_status(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'gone']], 404)]);

        $order = new Order();
        $order->external_order_id = 'bundle:99';
        $order->external_status = SupplierOrderResult::PENDING;

        $this->assertSame(SupplierOrderResult::PENDING, $this->connector()->checkOrder($order)->status);
    }

    /** A voucher that only completes on a later poll still reaches the customer. */
    public function test_check_order_captures_a_late_voucher_code(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'order' => [
            'status' => 'completed',
            'voucher' => ['code' => 'AAAA-BBBB'],
        ]], 200)]);

        $order = new Order();
        $order->external_order_id = 'telecom-voucher:5';
        $order->external_status = SupplierOrderResult::PENDING;

        $this->connector()->checkOrder($order);

        $this->assertStringContainsString('<li>AAAA-BBBB</li>', $order->code);
    }

    public function test_balance_converts_lbp_to_usd(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'wallet' => ['balance_lbp' => 5000000]], 200)]);

        $this->assertSame(round(5000000 / self::RATE, 4), $this->connector()->balance());
    }

    // ------------------------------------------------------------------ registry

    public function test_registry_resolves_umanage(): void
    {
        $registry = app(SupplierRegistry::class);

        $this->assertTrue($registry->has(UmanageConnector::KEY));
        $this->assertInstanceOf(UmanageConnector::class, $registry->get(UmanageConnector::KEY));
    }

    // ------------------------------------------------------------------ fixtures

    private function order(array $attributes): Order
    {
        $order = new Order();
        $order->id = 777;
        foreach ($attributes as $key => $value) {
            $order->{$key} = $value;
        }

        return $order;
    }

    private function variation(string $externalId): ProductsVariation
    {
        $variation = new ProductsVariation();
        $variation->external_id = $externalId;

        return $variation;
    }
}
