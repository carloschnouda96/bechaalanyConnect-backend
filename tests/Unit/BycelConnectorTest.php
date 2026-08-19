<?php

namespace Tests\Unit;

use App\Order;
use App\ProductsVariation;
use App\Services\Bycel\BycelBusinessException;
use App\Services\Bycel\BycelClient;
use App\Services\Bycel\BycelPinResolver;
use App\Services\Suppliers\Connectors\BycelConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Support\FakeBycelLedger;

/**
 * Catalog mapping and the purchase path. The ledger is substituted (see
 * FakeBycelLedger) so no database is touched.
 */
class BycelConnectorTest extends TestCase
{
    private const IMAGE = 'https://www.bycel.app/PowerGroupStore/Products/0735162408433465_Prepaid_03.png';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the product_list snapshot and purchase lock are cached
        config([
            'services.bycel.base_url' => 'https://www.bycel.app/OutAPIV1',
            'services.bycel.key' => 'TEST_key',
            'services.bycel.enabled' => true,
            'services.bycel.vouchers_enabled' => true,
            'services.bycel.recharge_enabled' => true,
            'services.bycel.report_page_sizes' => [3, 6],
            'services.bycel.claim_attempts' => 1,
            'services.bycel.claim_backoff_ms' => 0,
            'services.bycel.lock_wait_seconds' => 1,
            'services.bycel.match_product_name' => false,
            'services.bycel.rate_override' => null,
        ]);
    }

    /** The live row shape, from the PDF and the sample Power Group sent. */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'ProductId' => 11,
            'ProductCategoryId' => 1,
            'ProductCategoryDesc' => 'Telecom Lebanon',
            'ProductServiceId' => 2,
            'ProductServiceDesc' => 'Power Group',
            'ProductProviderId' => 1,
            'ProductProviderName' => 'Alfa',
            'ProductName' => 'alfa 4.5$ ',
            'IsActive' => true,
            'ProductImage' => self::IMAGE,
            'ValueOf1Dollar_LBP' => 89500,
            'FacePrice_USD' => 4.5,
            'FinalSellingPrice_LBP' => 467052.5,
            'MaxSalesQtyPerDay' => 100,
            'BuyVoucherEnabled' => 'E',
            'DirectRechargeEnabled' => 'E',
        ], $overrides);
    }

    private function reportRow(int $id, array $overrides = []): array
    {
        return array_merge([
            'MerchantPurchaseId' => $id,
            'ProductName' => 'alfa 4.5$ ',
            'Qty' => 1,
            'PinCode' => '411198407493764',
            'Serial' => '8150185238834',
            'DirectRecharge' => null,
            'FacePrice_USD' => 4.5,
            'FinalSellingPrice_LBP' => 467052.5,
            'PriceAfterDiscount_LBP' => 461212.625,
            'DiscountPercentage' => 1.45,
        ], $overrides);
    }

    private function connector(?FakeBycelLedger $ledger = null): array
    {
        $ledger ??= new FakeBycelLedger();
        $client = new BycelClient();
        $connector = new BycelConnector($client, $ledger, new BycelPinResolver($client, $ledger));

        return [$connector, $ledger];
    }

    private function order(array $attributes = []): Order
    {
        $order = new Order();
        $order->id = 501;
        $order->quantity = 1;
        $order->external_order_uuid = 'uuid-501';
        foreach ($attributes as $k => $v) {
            $order->{$k} = $v;
        }

        return $order;
    }

    private function variation(string $externalId): ProductsVariation
    {
        $v = new ProductsVariation();
        $v->external_id = $externalId;

        return $v;
    }

    /**
     * Routes each endpoint, and lets last_pin_report answer differently before and
     * after the purchase (watermark read, then claim read).
     */
    private function fakeApi(array $catalog, array $beforeReport, array $afterReport, $buyResponse = null): void
    {
        $calls = 0;
        Http::fake(function ($request) use ($catalog, $beforeReport, $afterReport, $buyResponse, &$calls) {
            $url = $request->url();

            if (str_contains($url, 'product_list')) {
                return Http::response($catalog, 200);
            }
            if (str_contains($url, 'last_pin_report')) {
                $calls++;
                return Http::response($calls === 1 ? $beforeReport : $afterReport, 200);
            }
            if (str_contains($url, 'buy_voucher') || str_contains($url, 'direct_recharge')) {
                if ($buyResponse instanceof \Throwable) {
                    throw $buyResponse;
                }
                return $buyResponse ?? Http::response([['Result' => 'OK']], 200);
            }

            return Http::response([['Result' => 'OK']], 200);
        });
    }

    // -------------------------------------------------------------------- catalog

    public function test_one_row_yields_both_a_voucher_and_a_recharge_product(): void
    {
        Http::fake(['*' => Http::response([$this->product()], 200)]);
        [$connector] = $this->connector();

        $catalog = $connector->fetchCatalog();
        $byId = collect($catalog)->keyBy->externalId;

        $this->assertCount(2, $catalog);

        $voucher = $byId['voucher:11'];
        $this->assertSame(2, $voucher->productTypeId);                 // Recharge By Code
        $this->assertSame('bycel-voucher-alfa', $voucher->categoryExternalId);
        $this->assertSame('Alfa Recharge Vouchers', $voucher->categoryName);
        $this->assertSame(round(467052.5 / 89500, 4), $voucher->unitCost);
        $this->assertSame(self::IMAGE, $voucher->image);
        $this->assertNull($voucher->categoryImage);
        $this->assertSame('Telecom Lebanon · Power Group', $voucher->externalType);

        $recharge = $byId['recharge:11'];
        $this->assertSame(3, $recharge->productTypeId);                // phone recipient
        $this->assertSame('bycel-recharge-alfa', $recharge->categoryExternalId);
        $this->assertStringContainsString('Direct Recharge', $recharge->name);

        $this->assertSame(['voucher:', 'recharge:'], $connector->catalogScopes());
    }

    /**
     * Bycel ships a DIFFERENT rate per row, which is why this connector must not
     * use the shared ExchangeRate helper.
     */
    public function test_each_row_is_priced_with_its_own_self_reported_rate(): void
    {
        Http::fake(['*' => Http::response([
            $this->product(['ProductId' => 11, 'ValueOf1Dollar_LBP' => 89500, 'FinalSellingPrice_LBP' => 467052.5, 'DirectRechargeEnabled' => 'I']),
            $this->product(['ProductId' => 12, 'ValueOf1Dollar_LBP' => 91000, 'FinalSellingPrice_LBP' => 467052.5, 'DirectRechargeEnabled' => 'I']),
        ], 200)]);
        [$connector] = $this->connector();

        $byId = collect($connector->fetchCatalog())->keyBy->externalId;

        $this->assertSame(round(467052.5 / 89500, 4), $byId['voucher:11']->unitCost);
        $this->assertSame(round(467052.5 / 91000, 4), $byId['voucher:12']->unitCost);
        $this->assertNotEquals($byId['voucher:11']->unitCost, $byId['voucher:12']->unitCost);
    }

    /** The real "Alfa Invoice" row: both flags 'I', zero price. */
    public function test_unsellable_and_zero_priced_rows_are_dropped(): void
    {
        Http::fake(['*' => Http::response([
            $this->product(['ProductId' => 2826, 'BuyVoucherEnabled' => 'I', 'DirectRechargeEnabled' => 'I', 'FinalSellingPrice_LBP' => 0.0, 'FacePrice_USD' => 0.0]),
            $this->product(['ProductId' => 13, 'FinalSellingPrice_LBP' => 0.0]),
            $this->product(['ProductId' => 14, 'DirectRechargeEnabled' => 'I']),
        ], 200)]);
        [$connector] = $this->connector();

        $ids = array_map(fn ($p) => $p->externalId, $connector->fetchCatalog());

        $this->assertSame(['voucher:14'], $ids);
    }

    public function test_rows_with_an_unusable_rate_are_dropped(): void
    {
        Http::fake(['*' => Http::response([
            $this->product(['ProductId' => 15, 'ValueOf1Dollar_LBP' => 0]),
            $this->product(['ProductId' => 16, 'ValueOf1Dollar_LBP' => 12]),      // absurd
            $this->product(['ProductId' => 17]),
        ], 200)]);
        [$connector] = $this->connector();

        $ids = array_map(fn ($p) => $p->externalId, $connector->fetchCatalog());

        $this->assertNotContains('voucher:15', $ids);
        $this->assertNotContains('voucher:16', $ids);
        $this->assertContains('voucher:17', $ids);
    }

    /** Supplier margin is never negative, so a cost below face means bad data. */
    public function test_row_deriving_a_cost_below_its_own_face_value_is_dropped(): void
    {
        Http::fake(['*' => Http::response([
            $this->product(['ProductId' => 18, 'FacePrice_USD' => 50.0]),
        ], 200)]);
        [$connector] = $this->connector();

        $this->assertSame([], $connector->fetchCatalog());
    }

    public function test_inactive_rows_are_emitted_as_unavailable_not_dropped(): void
    {
        Http::fake(['*' => Http::response([$this->product(['IsActive' => false, 'DirectRechargeEnabled' => 'I'])], 200)]);
        [$connector] = $this->connector();

        $catalog = $connector->fetchCatalog();

        $this->assertCount(1, $catalog);
        $this->assertFalse($catalog[0]->available);
    }

    public function test_providers_get_separate_categories(): void
    {
        Http::fake(['*' => Http::response([
            $this->product(['ProductId' => 20, 'ProductProviderName' => 'Alfa', 'DirectRechargeEnabled' => 'I']),
            $this->product(['ProductId' => 21, 'ProductProviderName' => 'Touch', 'DirectRechargeEnabled' => 'I']),
        ], 200)]);
        [$connector] = $this->connector();

        $cats = array_unique(array_map(fn ($p) => $p->categoryExternalId, $connector->fetchCatalog()));
        sort($cats);

        $this->assertSame(['bycel-voucher-alfa', 'bycel-voucher-touch'], $cats);
    }

    // ------------------------------------------------------- pre-flight rejections

    public function test_quantity_above_one_fails_without_any_http(): void
    {
        Http::fake();
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(['quantity' => 2]), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertNotNull($result->externalOrderId);
        Http::assertNothingSent();
    }

    public function test_unrecognised_external_id_fails_without_any_http(): void
    {
        Http::fake();
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(), $this->variation('legacy-11'));

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_recharge_without_a_phone_fails_without_any_http(): void
    {
        Http::fake();
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(['recipient_phone_number' => '']), $this->variation('recharge:11'));

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        Http::assertNothingSent();
    }

    public function test_daily_cap_is_enforced_before_spending(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(101), $this->reportRow(99)]);
        $ledger = new FakeBycelLedger();
        $ledger->soldToday = 100; // MaxSalesQtyPerDay is 100
        [$connector] = $this->connector($ledger);

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertSame(0, $ledger->openIntentCalls);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'buy_voucher'));
    }

    // ------------------------------------------------------------- purchase paths

    public function test_voucher_purchase_claims_the_pin_and_renders_it_as_li_html(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(101), $this->reportRow(99)]);
        [$connector, $ledger] = $this->connector();

        $order = $this->order();
        $result = $connector->placeOrder($order, $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        $this->assertSame('voucher:101', $result->externalOrderId);
        $this->assertSame(1, $ledger->openIntentCalls);

        // order-codes.tsx renders NOTHING without <li> elements.
        $this->assertStringContainsString('<li>411198407493764</li>', $order->code);
        $this->assertStringContainsString('Serial: 8150185238834', $order->code);
        $this->assertSame(1, substr_count($order->code, '<li>'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'buy_voucher') && $r->hasHeader('InApiKey', 'TEST_key'));
    }

    /** The intent (and the order pre-write) must exist before the POST. */
    public function test_the_order_is_pre_written_before_the_purchase(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)]);
        [$connector] = $this->connector();

        $order = $this->order();
        $connector->placeOrder($order, $this->variation('voucher:11'));

        // Even with no claim, the order carries a non-null id — which is what stops
        // the CMS Retry button and the job's 3 retries from buying a second card.
        $this->assertNotNull($order->external_order_id);
        $this->assertStringStartsWith('voucher:', $order->external_order_id);
    }

    public function test_definitive_failure_refunds(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)], Http::response([['Result' => 'NO']], 200));
        [$connector, $ledger] = $this->connector();

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertSame(\App\BycelPurchase::STATE_FAILED, $ledger->purchase->state);
    }

    /** An unrecognised reply must NOT refund — the card may have been bought. */
    public function test_unrecognised_reply_is_pending_not_failed(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)], Http::response([['Result' => 'Some undocumented problem']], 200));
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertNotNull($result->externalOrderId);
    }

    public function test_dropped_connection_is_pending_not_failed(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)], new ConnectionException('cURL error 28'));
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertNotNull($result->externalOrderId);
    }

    /**
     * An IP-whitelist lapse or a rotated key must retry, never mass-refund.
     */
    public function test_auth_and_ip_rejections_are_retryable(): void
    {
        foreach (['Unauthorized client', '192.168.0.1 Forbidden'] as $body) {
            Cache::flush();
            $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)], Http::response($body, 200));
            [$connector] = $this->connector();

            try {
                $connector->placeOrder($this->order(), $this->variation('voucher:11'));
                $this->fail("Expected '{$body}' to throw a retryable exception.");
            } catch (SupplierApiException $e) {
                $this->assertNotInstanceOf(BycelBusinessException::class, $e, "'{$body}' must not refund the customer");
            }
        }
    }

    /**
     * The REAL response observed from Bycel on 2026-08-13 when the calling IP is not
     * whitelisted: HTTP 403 with a bare, non-JSON body. It must be retryable —
     * classifying an IP-whitelist lapse as a business failure would mass-refund and
     * reject every pending order the moment a host migration changes the egress IP.
     */
    public function test_live_ip_forbidden_response_is_retryable_not_a_failure(): void
    {
        Http::fake(['*' => Http::response('185.115.101.218 Forbidden', 403)]);
        [$connector] = $this->connector();

        try {
            $connector->fetchCatalog();
            $this->fail('An un-whitelisted IP must raise a retryable exception.');
        } catch (SupplierApiException $e) {
            $this->assertNotInstanceOf(BycelBusinessException::class, $e);
            $this->assertStringContainsString('authorisation/IP rejection', $e->getMessage());
        }
    }

    /** The documented bad-key reply, which is also plain text rather than JSON. */
    public function test_unauthorized_client_response_is_retryable(): void
    {
        Http::fake(['*' => Http::response('Unauthorized client', 200)]);
        [$connector] = $this->connector();

        $this->expectException(SupplierApiException::class);
        $connector->fetchCatalog();
    }

    /**
     * "We have less quantity in our stock!" READS like an error but the order WAS
     * placed. It must never reach the refund branch.
     */
    public function test_partial_stock_reply_is_treated_as_a_purchase(): void
    {
        $this->fakeApi(
            [$this->product()],
            [$this->reportRow(99)],
            [$this->reportRow(101), $this->reportRow(99)],
            Http::response([['Result' => 'We have less quantity in our stock! You could take only 1 out of 2.']], 200)
        );
        [$connector] = $this->connector();

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        $this->assertSame('voucher:101', $result->externalOrderId);
    }

    /** A contested row is never delivered, even though the purchase succeeded. */
    public function test_claim_conflict_parks_the_order_instead_of_delivering(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(101), $this->reportRow(99)]);
        $ledger = new FakeBycelLedger();
        $ledger->failNextClaimWithConflict = true;
        [$connector] = $this->connector($ledger);

        $order = $this->order();
        $result = $connector->placeOrder($order, $this->variation('voucher:11'));

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertNull($order->code);
    }

    /** A recharge Bycel confirmed is delivered even if no audit row is claimable. */
    public function test_recharge_completes_on_ok_without_a_claimable_row(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)]);
        [$connector] = $this->connector();

        $result = $connector->placeOrder(
            $this->order(['recipient_phone_number' => '70 476 165']),
            $this->variation('recharge:11')
        );

        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'direct_recharge') && $r['mobilenum'] === '70476165');
    }

    /** An existing live intent must never trigger a second purchase. */
    public function test_an_existing_intent_is_never_re_purchased(): void
    {
        $this->fakeApi([$this->product()], [$this->reportRow(99)], [$this->reportRow(99)]);

        $existing = new \App\BycelPurchase([
            'order_id' => 501, 'order_uuid' => 'uuid-501', 'family' => 'voucher',
            'product_id' => '11', 'watermark_id' => 99, 'state' => \App\BycelPurchase::STATE_SENT,
            'snapshot' => ['FinalSellingPrice_LBP' => 467052.5, 'FacePrice_USD' => 4.5],
        ]);
        $existing->id = 7;
        $existing->response_result = 'OK';

        [$connector, $ledger] = $this->connector(new FakeBycelLedger($existing));

        $result = $connector->placeOrder($this->order(), $this->variation('voucher:11'));

        $this->assertSame(0, $ledger->openIntentCalls);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'buy_voucher'));
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
    }

    public function test_balance_converts_lbp_using_a_catalog_rate(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'product_list')) {
                return Http::response([$this->product()], 200);
            }
            return Http::response([['Result' => '200000', 'Currency' => 'LBP']], 200);
        });
        [$connector] = $this->connector();

        $this->assertSame(round(200000 / 89500, 4), $connector->balance());
    }

    public function test_registry_resolves_bycel(): void
    {
        $registry = app(SupplierRegistry::class);

        $this->assertTrue($registry->has(BycelConnector::KEY));
        $this->assertInstanceOf(BycelConnector::class, $registry->get(BycelConnector::KEY));
    }
}
