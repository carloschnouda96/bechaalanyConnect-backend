<?php

namespace Tests\Unit;

use App\Order;
use App\Services\Suppliers\Connectors\SwiftConnector;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Swift\SwiftClient;
use App\Services\Yassen\YassenClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit coverage for the multi-supplier framework. These exercise the pure
 * mapping/normalisation logic with faked HTTP — no database is touched, so they
 * are safe to run against the real MySQL test DB.
 */
class SupplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.swift.base_url' => 'https://swiftservices.store',
            'services.swift.key' => 'test-key',
            'services.yassen.base_url' => 'https://api.yassen-card.com',
            'services.yassen.token' => 'test-token',
        ]);
    }

    /**
     * Unit cost depends on the Perfect Panel service type: "Package" services
     * (the whole live Swift catalog) charge a flat `rate` per unit; "Default" SMM
     * services charge `rate` per 1000. Category grouping is by the `category` name.
     */
    public function test_swift_maps_rate_to_unit_cost_by_type(): void
    {
        Http::fake(['*' => Http::response([
            ['service' => 42, 'name' => 'NETFLIX 4K PREMIUM MONTH', 'type' => 'Package', 'category' => 'Movie Streaming Services', 'rate' => 8, 'min' => 1, 'max' => 1],
            ['service' => 51, 'name' => 'ANGHAMI 6 MONTHS', 'type' => 'Package', 'category' => 'Music Streaming', 'rate' => 19.8, 'min' => 1, 'max' => 1],
            ['service' => 99, 'name' => 'Instagram Followers', 'type' => 'Default', 'category' => 'Social', 'rate' => '5000', 'min' => 100, 'max' => 10000],
        ], 200)]);

        $catalog = (new SwiftConnector(new SwiftClient()))->fetchCatalog();

        $this->assertCount(3, $catalog);

        $netflix = $catalog[0];
        $this->assertSame('42', $netflix->externalId);
        $this->assertSame(8.0, $netflix->unitCost);                    // Package → flat rate
        $this->assertSame('Movie Streaming Services', $netflix->categoryName);
        $this->assertSame('movie-streaming-services', $netflix->categoryExternalId);
        $this->assertSame(1, $netflix->productTypeId);                 // recipient/link field
        $this->assertTrue($netflix->available);                        // presence implies available
        $this->assertSame(['min' => 1, 'max' => 1], $netflix->qtyValues);

        $this->assertSame(19.8, $catalog[1]->unitCost);                // Package → flat rate
        $this->assertSame('music-streaming', $catalog[1]->categoryExternalId);

        $this->assertSame(5.0, $catalog[2]->unitCost);                 // Default → 5000 / 1000
    }

    /** Swift status vocabulary normalises to pending|completed|failed. */
    public function test_swift_status_mapping(): void
    {
        $cases = [
            'Completed' => SupplierOrderResult::COMPLETED,
            'In progress' => SupplierOrderResult::PENDING,
            'Awaiting' => SupplierOrderResult::PENDING,
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
            $body = ['charge' => '5', 'start_count' => '0', 'status' => $supplierStatus, 'remains' => '0', 'currency' => 'USD'];

            $order = new Order();
            $order->external_order_id = '5001';
            $order->external_status = SupplierOrderResult::PENDING;

            $result = (new SwiftConnector(new SwiftClient()))->checkOrder($order);

            $this->assertSame($expected, $result->status, "Swift status '{$supplierStatus}' should map to '{$expected}'");
        }
    }

    /** Yassen status vocabulary normalises to pending|completed|failed. */
    public function test_yassen_status_mapping(): void
    {
        $cases = [
            'accept' => SupplierOrderResult::COMPLETED,
            'wait' => SupplierOrderResult::PENDING,
            'reject' => SupplierOrderResult::FAILED,
        ];

        $body = [];
        Http::fake(function () use (&$body) { return Http::response($body, 200); });

        foreach ($cases as $supplierStatus => $expected) {
            $body = ['status' => $supplierStatus];

            $order = new Order();
            $order->external_order_id = '777';
            $order->external_status = SupplierOrderResult::PENDING;

            $result = (new YassenConnector(new YassenClient()))->checkOrder($order);

            $this->assertSame($expected, $result->status, "Yassen status '{$supplierStatus}' should map to '{$expected}'");
        }
    }

    /**
     * The import_excluded switch keeps a product inactive even when the supplier
     * still offers it (available=true), and an excluded product is never made
     * active by a sync.
     */
    public function test_import_excluded_forces_inactive(): void
    {
        $this->assertSame(1, SupplierCatalogSync::isActiveAfterImport(true, false));  // offered, not excluded → active
        $this->assertSame(0, SupplierCatalogSync::isActiveAfterImport(true, true));   // offered but excluded → inactive
        $this->assertSame(0, SupplierCatalogSync::isActiveAfterImport(false, false)); // not offered → inactive
        $this->assertSame(0, SupplierCatalogSync::isActiveAfterImport(false, true));  // not offered + excluded → inactive
    }
}
