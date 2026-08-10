<?php

namespace Tests\Unit;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\UsharezConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use App\Services\Usharez\UsharezBusinessException;
use App\Services\Usharez\UsharezClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit coverage for the usharez connector. Pure mapping / normalisation logic
 * with faked HTTP — no database is touched (the LBP→USD rate is injected rather
 * than read from fixed_settings), so it's safe against the real MySQL test DB.
 */
class UsharezConnectorTest extends TestCase
{
    /** The live payload from GET /api/quota/admin/stock, verbatim. */
    private const QUOTA_STOCK = [
        'success' => true,
        'data' => [
            ['quota_size' => '67 GB', 'price' => 2220000],
            ['quota_size' => '56 GB', 'price' => 1920000],
            ['quota_size' => '44 GB', 'price' => 1600000],
            ['quota_size' => '33 GB', 'price' => 1280000],
            ['quota_size' => '22 GB', 'price' => 980000],
            ['quota_size' => '16.5 GB', 'price' => 820000],
            ['quota_size' => '11 GB', 'price' => 660000],
            ['quota_size' => '5.5 GB', 'price' => 510000],
        ],
    ];

    private const RATE = 89500.0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.usharez.base_url' => 'https://bechaalanyconnect.usharez.com',
            'services.usharez.token' => 'test-token',
            'services.usharez.enabled' => true,
            'services.usharez.lbp_per_usd' => self::RATE,
            'services.usharez.quota_enabled' => true,
            'services.usharez.gifts_enabled' => false,
            'services.usharez.gifts_currency' => 'usd',
            'services.usharez.gift_max_qty' => 10,
            'services.usharez.phone_format' => 'national8',
        ]);
    }

    private function connector(?float $rate = self::RATE): UsharezConnector
    {
        return new UsharezConnector(new UsharezClient(), $rate);
    }

    // ------------------------------------------------------------------ catalog

    /** Quota prices arrive in LBP and must be divided into USD before storage. */
    public function test_quota_stock_maps_lbp_to_usd_and_encodes_family(): void
    {
        Http::fake(['*' => Http::response(self::QUOTA_STOCK, 200)]);

        $catalog = $this->connector()->fetchCatalog();

        $this->assertCount(8, $catalog);

        $first = $catalog[0];
        $this->assertSame('quota:67', $first->externalId);
        $this->assertSame('67 GB', $first->name);
        $this->assertSame(round(2220000 / self::RATE, 4), $first->unitCost);
        $this->assertSame(UsharezConnector::CATEGORY_QUOTA, $first->categoryExternalId);
        $this->assertSame('Internet Quota Bundles', $first->categoryName);
        $this->assertSame(3, $first->productTypeId);           // Telecommunication Charge
        $this->assertTrue($first->available);
        $this->assertSame(['min' => 1, 'max' => 1], $first->qtyValues);
        $this->assertSame('quota', $first->externalType);

        // 660000 / 89500 ≈ 7.3743 — a sanity check that the magnitude is sane.
        $elevenGb = $catalog[6];
        $this->assertSame('quota:11', $elevenGb->externalId);
        $this->assertEqualsWithDelta(7.37, $elevenGb->unitCost, 0.01);
    }

    /** Fractional sizes must survive the round trip without gaining ".00". */
    public function test_fractional_quota_sizes_round_trip(): void
    {
        Http::fake(['*' => Http::response(self::QUOTA_STOCK, 200)]);

        $ids = array_map(fn ($p) => $p->externalId, $this->connector()->fetchCatalog());

        $this->assertContains('quota:16.5', $ids);
        $this->assertContains('quota:5.5', $ids);
        $this->assertContains('quota:67', $ids);
        $this->assertNotContains('quota:67.00', $ids);
    }

    public function test_unparseable_quota_row_is_skipped_not_fatal(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['quota_size' => 'Unlimited', 'price' => 100000],   // no digits
            ['quota_size' => '11 GB'],                          // no price
            ['quota_size' => '22 GB', 'price' => 980000],       // good
        ]], 200)]);

        $catalog = $this->connector()->fetchCatalog();

        $this->assertCount(1, $catalog);
        $this->assertSame('quota:22', $catalog[0]->externalId);
    }

    /**
     * The live account gets HTTP 403 on /api/alfa-gifts. That must not take the
     * quota catalog down with it, and the connector must not vouch for the gift
     * family (or the sync engine would deactivate every gift product).
     */
    public function test_gift_family_403_leaves_quota_catalog_intact(): void
    {
        config(['services.usharez.gifts_enabled' => true]);

        Http::fake([
            '*api/quota/admin/stock*' => Http::response(self::QUOTA_STOCK, 200),
            '*api/alfa-gifts*' => Http::response(['success' => false, 'message' => 'Forbidden'], 403),
        ]);

        $connector = $this->connector();
        $catalog = $connector->fetchCatalog();

        $this->assertCount(8, $catalog);
        $this->assertSame(['quota:'], $connector->catalogScopes());
    }

    /** An empty stock list is indistinguishable from an outage — vouch for nothing. */
    public function test_empty_quota_stock_does_not_vouch_for_the_family(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

        $connector = $this->connector();

        $this->assertSame([], $connector->fetchCatalog());
        $this->assertSame([], $connector->catalogScopes());
    }

    /** Every enabled family failing means the supplier is down, not empty. */
    public function test_all_families_failing_throws(): void
    {
        config(['services.usharez.gifts_enabled' => true]);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Forbidden'], 403)]);

        $this->expectException(SupplierApiException::class);
        $this->connector()->fetchCatalog();
    }

    /** The gifts payload is unverified, so the parser tolerates key-spelling drift. */
    public function test_gift_parser_tolerates_alternate_key_spellings(): void
    {
        config([
            'services.usharez.gifts_enabled' => true,
            'services.usharez.quota_enabled' => false,
        ]);

        Http::fake(['*api/alfa-gifts*' => Http::response(['data' => [
            ['service' => 'Alfa $0.5', 'price' => 0.5],
            ['name' => 'Alfa $1', 'amount' => 1.0],
            ['title' => 'Alfa $2', 'rate' => 2, 'available' => false],
            ['service' => 'No price here'],   // skipped
        ]], 200)]);

        $catalog = $this->connector()->fetchCatalog();

        $this->assertCount(3, $catalog);
        $this->assertSame('gift:Alfa $0.5', $catalog[0]->externalId);
        $this->assertSame(0.5, $catalog[0]->unitCost);        // gifts_currency = usd → no division
        $this->assertSame(3, $catalog[0]->productTypeId);
        $this->assertSame(['min' => 1, 'max' => 10], $catalog[0]->qtyValues);
        $this->assertSame('gift:Alfa $1', $catalog[1]->externalId);
        $this->assertFalse($catalog[2]->available);
    }

    /** A zero divisor would produce INF prices — refuse to run at all. */
    public function test_zero_lbp_divisor_makes_connector_unconfigured(): void
    {
        $connector = $this->connector(0.0);

        $this->assertFalse($connector->isConfigured());

        $this->expectException(SupplierApiException::class);
        $connector->fetchCatalog();
    }

    // ----------------------------------------------------------------- ordering

    public function test_place_quota_order_posts_normalised_phone_and_integer_size(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message' => 'Allocated'], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03 123 456', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        $this->assertNotNull($result->externalOrderId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/quota/admin/allocate')
                && $request['phoneNumber'] === '03123456'
                && $request['quotaSize'] === 11          // int, as the docs show
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_place_quota_order_sends_float_for_fractional_size(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('quota:16.5')
        );

        Http::assertSent(fn ($request) => $request['quotaSize'] === 16.5);
    }

    /**
     * usharez returns no order id, and SupplierOrderFulfillment::fulfill() treats a
     * non-null external_order_id as its only re-entry guard — so a null here would
     * let a job retry re-POST and double-charge.
     */
    public function test_synthetic_order_id_is_derived_from_the_dedupe_uuid(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $order = $this->order([
            'recipient_phone_number' => '03123456',
            'quantity' => 1,
            'external_order_uuid' => 'abc-123',
        ]);

        $result = $this->connector()->placeOrder($order, $this->variation('quota:11'));

        $this->assertSame('usharez-abc-123', $result->externalOrderId);
    }

    /** When the API does return an id-ish field, prefer it over the synthetic one. */
    public function test_supplier_order_id_is_preferred_when_present(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 9911]], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        $this->assertSame('9911', $result->externalOrderId);
    }

    /** A definitive rejection must refund, i.e. surface as FAILED (not an exception). */
    public function test_business_error_returns_failed_so_the_engine_refunds(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Insufficient balance'], 400)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertStringContainsString('Insufficient balance', $result->raw['usharez_error']);
    }

    public function test_success_false_on_http_200_returns_failed(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid number'], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
    }

    /** 5xx must propagate so the job retries rather than refunding the customer. */
    public function test_server_error_throws_so_the_job_retries(): void
    {
        Http::fake(['*' => Http::response('gateway down', 503)]);

        try {
            $this->connector()->placeOrder(
                $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
                $this->variation('quota:11')
            );
            $this->fail('Expected a SupplierApiException for a 503.');
        } catch (SupplierApiException $e) {
            $this->assertNotInstanceOf(UsharezBusinessException::class, $e);
        }
    }

    /** A revoked token must not look like a customer-facing rejection. */
    public function test_401_is_retryable_not_a_refund(): void
    {
        Http::fake(['*' => Http::response(['message' => 'API token is required.'], 401)]);

        try {
            $this->connector()->placeOrder(
                $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
                $this->variation('quota:11')
            );
            $this->fail('Expected a SupplierApiException for a 401.');
        } catch (SupplierApiException $e) {
            $this->assertNotInstanceOf(UsharezBusinessException::class, $e);
        }
    }

    /**
     * A dropped POST may or may not have been fulfilled: park it PENDING with an
     * id so it is neither refunded nor automatically re-placed.
     */
    public function test_indeterminate_post_returns_pending_with_synthetic_id(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1, 'external_order_uuid' => 'uuid-9']),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertSame('usharez-uuid-9', $result->externalOrderId);
        $this->assertTrue($result->raw['usharez_indeterminate']);
    }

    /** allocate() has no qty parameter, so >1 must never reach the API. */
    public function test_quota_order_above_quantity_one_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 3]),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    /** saveOrder does not validate the recipient, so the connector is the backstop. */
    public function test_missing_phone_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_unrecognised_external_id_fails_without_calling_the_api(): void
    {
        Http::fake();

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('legacy-123')
        );

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    /** Gifts DO take a qty, so it passes straight through. */
    public function test_gift_order_passes_quantity_through(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 4]),
            $this->variation('gift:Alfa $0.5')
        );

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/alfa-gifts/purchase')
                && $request['service'] === 'Alfa $0.5'
                && $request['recipient_phone_number'] === '03123456'
                && $request['qty'] === 4;
        });
    }

    /** There is no status endpoint — checkOrder must never hit the network. */
    public function test_check_order_makes_no_http_call_and_echoes_status(): void
    {
        Http::fake();

        $order = new Order();
        $order->external_order_id = 'usharez-abc';
        $order->external_status = SupplierOrderResult::PENDING;
        $order->external_response = ['usharez_indeterminate' => true];

        $result = $this->connector()->checkOrder($order);

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertSame('usharez-abc', $result->externalOrderId);
        Http::assertNothingSent();
    }

    public function test_balance_converts_lbp_to_usd(): void
    {
        Http::fake(['*' => Http::response(['balance' => 1500000, 'debt_allowance' => 500000], 200)]);

        $this->assertSame(round(1500000 / self::RATE, 4), $this->connector()->balance());
    }

    // -------------------------------------------------------------- normalisation

    /**
     * Lebanese mobile numbers are 8 national digits; the leading 0 belongs to the
     * operator prefix and international form drops it.
     *
     * @dataProvider phoneProvider
     */
    public function test_phone_normalisation_variants(string $input, ?string $expected): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $result = $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => $input, 'quantity' => 1]),
            $this->variation('quota:11')
        );

        if ($expected === null) {
            $this->assertSame(SupplierOrderResult::FAILED, $result->status);
            Http::assertNothingSent();
            return;
        }

        Http::assertSent(fn ($request) => $request['phoneNumber'] === $expected);
    }

    public static function phoneProvider(): array
    {
        return [
            'plain national'      => ['03123456', '03123456'],
            'spaced'              => ['03 123 456', '03123456'],
            'missing leading 0'   => ['3123456', '03123456'],
            'international +961'  => ['+961 3 123456', '03123456'],
            'international 00961' => ['0096171123456', '71123456'],
            'other operator'      => ['71123456', '71123456'],
            'empty'               => ['', null],
            'too short'           => ['123', null],
        ];
    }

    /** The strip_leading_zero escape hatch, in case a live order proves it's needed. */
    public function test_strip_leading_zero_phone_format(): void
    {
        config(['services.usharez.phone_format' => 'strip_leading_zero']);
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->connector()->placeOrder(
            $this->order(['recipient_phone_number' => '03123456', 'quantity' => 1]),
            $this->variation('quota:11')
        );

        Http::assertSent(fn ($request) => $request['phoneNumber'] === '3123456');
    }

    // ------------------------------------------------------------------ registry

    /** Without this, SupplierOrderFulfillment::isExternal() never fulfils a usharez order. */
    public function test_registry_resolves_usharez(): void
    {
        $registry = app(SupplierRegistry::class);

        $this->assertTrue($registry->has(UsharezConnector::KEY));
        $this->assertInstanceOf(UsharezConnector::class, $registry->get(UsharezConnector::KEY));
    }

    // ------------------------------------------------------------------- fixtures

    private function order(array $attributes): Order
    {
        $order = new Order();
        $order->id = 4242;
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
