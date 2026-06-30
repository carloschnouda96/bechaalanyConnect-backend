<?php

namespace Tests\Unit;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\OneXPanelConnector;
use App\Services\Suppliers\SupplierOrderResult;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit coverage for the 1xpanel connector (Perfect Panel v2 via
 * PerfectPanelConnector / PerfectPanelClient). Pure mapping/normalisation logic
 * with faked HTTP — no database is touched, so it's safe against the real MySQL
 * test DB. The connector reads its config block, so we seed it here.
 */
class OneXPanelConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.1xpanel.base_url' => 'https://1xpanel.com',
            'services.1xpanel.key' => 'test-key',
            'services.1xpanel.enabled' => true,
        ]);
    }

    /**
     * Unit cost depends on the Perfect Panel service type: "Package" services
     * charge a flat `rate` per unit; classic "Default" SMM services charge `rate`
     * per 1000. Category grouping is by a slug of the `category` name.
     */
    public function test_maps_rate_to_unit_cost_by_type(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => 1, 'name' => 'Followers', 'type' => 'Default', 'category' => 'First Category', 'rate' => '0.90', 'min' => '50', 'max' => '10000'],
            ['service' => 7, 'name' => 'Netflix 1 Month', 'type' => 'Package', 'category' => 'Streaming', 'rate' => 8, 'min' => 1, 'max' => 1],
        ], 200)]);

        $catalog = (new OneXPanelConnector())->fetchCatalog();

        $this->assertCount(2, $catalog);

        $followers = $catalog[0];
        $this->assertSame('1', $followers->externalId);
        $this->assertSame(0.90 / 1000, $followers->unitCost);          // Default → rate / 1000
        $this->assertSame('First Category', $followers->categoryName);
        $this->assertSame('first-category', $followers->categoryExternalId);
        $this->assertSame(1, $followers->productTypeId);               // recipient/link field
        $this->assertTrue($followers->available);                      // presence implies available
        $this->assertSame(['min' => 50, 'max' => 10000], $followers->qtyValues);

        $this->assertSame(8.0, $catalog[1]->unitCost);                 // Package → flat rate
        $this->assertSame('streaming', $catalog[1]->categoryExternalId);
    }

    /** 1xpanel status vocabulary normalises to pending|completed|failed. */
    public function test_status_mapping(): void
    {
        $cases = [
            'Completed' => SupplierOrderResult::COMPLETED,
            'In progress' => SupplierOrderResult::PENDING,
            'Partial' => SupplierOrderResult::PENDING,
            'Canceled' => SupplierOrderResult::FAILED,
            'Fail' => SupplierOrderResult::FAILED,
        ];

        // Single by-reference stub: repeated Http::fake() calls push additional
        // stubs and the first wildcard match always wins, which would leak the
        // first case's response into every iteration.
        $body = [];
        Http::fake(function () use (&$body) { return Http::response($body, 200); });

        foreach ($cases as $supplierStatus => $expected) {
            $body = ['charge' => '0.27', 'start_count' => '100', 'status' => $supplierStatus, 'remains' => '0', 'currency' => 'USD'];

            $order = new Order();
            $order->external_order_id = '23501';
            $order->external_status = SupplierOrderResult::PENDING;

            $result = (new OneXPanelConnector())->checkOrder($order);

            $this->assertSame($expected, $result->status, "1xpanel status '{$supplierStatus}' should map to '{$expected}'");
        }
    }

    /** placeOrder sends the recipient as `link` and returns the supplier order id, pending. */
    public function test_place_order_returns_pending_with_order_id(): void
    {
        Http::fake(['*' => Http::response(['order' => 23501], 200)]);

        $variation = new ProductsVariation();
        $variation->external_id = '1';

        $order = new Order();
        $order->recipient_user = 'https://instagram.com/example';
        $order->quantity = 500;

        $result = (new OneXPanelConnector())->placeOrder($order, $variation);

        $this->assertSame('23501', $result->externalOrderId);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
    }
}
