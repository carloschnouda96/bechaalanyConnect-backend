<?php

namespace Tests\Unit;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\OneXPanelConnector;
use App\Services\Suppliers\Connectors\SwiftConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Swift\SwiftClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A supplier order whose reply was lost may well have been processed, and these panels have no
 * dedupe key, so re-sending it can buy twice. Same rule as usharez and U-Manage's dropped POST:
 * park it PENDING for a human, never re-send and never refund.
 * Regression from the 2026-11 security audit (run-2).
 */
class SupplierWriteReplayTest extends TestCase
{
    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.1xpanel.base_url' => 'https://1xpanel.test',
            'services.1xpanel.key' => 'k',
            'services.1xpanel.enabled' => true,
        ]);
    }

    private function fakeWrites(callable $reply): void
    {
        Http::fake(function ($request) use ($reply) {
            $this->posts++;

            return $reply($this->posts);
        });
    }

    private function order(): Order
    {
        $order = new Order();
        $order->id = 77;
        $order->recipient_user = 'https://example.test/me';
        $order->quantity = 100;
        $order->external_order_uuid = 'uuid-77';

        return $order;
    }

    private function variation(): ProductsVariation
    {
        $variation = new ProductsVariation();
        $variation->external_id = '1';

        return $variation;
    }

    private function swift(): SwiftConnector
    {
        return new SwiftConnector(new SwiftClient('https://swift.test', 'k'));
    }

    public function test_a_panel_timeout_parks_the_order_after_one_post(): void
    {
        $this->fakeWrites(fn () => throw new ConnectionException('timed out'));

        $result = (new OneXPanelConnector())->placeOrder($this->order(), $this->variation());

        $this->assertSame(1, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertNotEmpty($result->externalOrderId, 'a non-null id keeps fulfill() from re-sending');
    }

    public function test_a_panel_5xx_parks_the_order_after_one_post(): void
    {
        $this->fakeWrites(fn () => Http::response('Bad Gateway', 502));

        $result = (new OneXPanelConnector())->placeOrder($this->order(), $this->variation());

        $this->assertSame(1, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
    }

    public function test_a_panel_4xx_still_throws_because_nothing_was_processed(): void
    {
        $this->fakeWrites(fn () => Http::response('nope', 401));

        $this->expectException(SupplierApiException::class);
        (new OneXPanelConnector())->placeOrder($this->order(), $this->variation());
    }

    public function test_a_parked_panel_order_is_not_polled(): void
    {
        $this->fakeWrites(fn () => throw new ConnectionException('timed out'));
        $order = $this->order();
        $order->external_order_id = (new OneXPanelConnector())->placeOrder($order, $this->variation())->externalOrderId;
        $order->external_status = SupplierOrderResult::PENDING;
        $this->posts = 0;

        $result = (new OneXPanelConnector())->checkOrder($order);

        $this->assertSame(0, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
    }

    public function test_a_panel_read_still_retries(): void
    {
        $this->fakeWrites(fn (int $n) => $n === 1 ? Http::response('down', 503) : Http::response(['status' => 'Completed'], 200));
        $order = $this->order();
        $order->external_order_id = '555';

        $result = (new OneXPanelConnector())->checkOrder($order);

        $this->assertSame(2, $this->posts);
        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
    }

    public function test_a_swift_timeout_parks_the_order_after_one_post(): void
    {
        $this->fakeWrites(fn () => throw new ConnectionException('timed out'));

        $result = $this->swift()->placeOrder($this->order(), $this->variation());

        $this->assertSame(1, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
    }

    public function test_a_swift_5xx_parks_the_order_and_is_not_polled(): void
    {
        $this->fakeWrites(fn () => Http::response('Bad Gateway', 502));
        $connector = $this->swift();
        $order = $this->order();

        $result = $connector->placeOrder($order, $this->variation());
        $this->assertSame(1, $this->posts);
        $this->assertSame(SupplierOrderResult::PENDING, $result->status);

        $order->external_order_id = $result->externalOrderId;
        $order->external_status = SupplierOrderResult::PENDING;
        $this->posts = 0;
        $connector->checkOrder($order);
        $this->assertSame(0, $this->posts);
    }
}
