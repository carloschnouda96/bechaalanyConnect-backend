<?php

namespace Tests\Unit;

use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\AlloSharejleConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Allo Sharejle runs the Yassen platform, so AlloSharejleConnector is
 * YassenConnector with its own key and config. Pins that it talks to its own
 * host with its own token (never Yassen's), reads the documented `data`-wrapped
 * order reply, and refunds only on the documented final error codes.
 */
class AlloSharejleConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.allosharejle.base_url' => 'https://api.allosharejle.com',
            'services.allosharejle.token' => 'allo-token',
            'services.allosharejle.enabled' => true,
            'services.yassen.base_url' => 'https://api.yassen-card.com',
            'services.yassen.token' => 'yassen-token',
        ]);
    }

    private function order(): Order
    {
        $order = new Order();
        $order->quantity = 2;
        $order->external_order_uuid = 'uuid-allo-1';
        $order->recipient_user = 'player-9';

        return $order;
    }

    private function variation(): ProductsVariation
    {
        $product = new Product();
        $product->external_source = AlloSharejleConnector::KEY;
        $product->external_id = '364';

        $variation = new ProductsVariation();
        $variation->external_id = '364';
        $variation->setRelation('product', $product);

        return $variation;
    }

    public function test_it_is_registered_under_its_own_key(): void
    {
        $connector = app(SupplierRegistry::class)->get('allosharejle');

        $this->assertInstanceOf(AlloSharejleConnector::class, $connector);
        $this->assertSame('allosharejle', $connector->key());
        $this->assertTrue($connector->isEnabled());
        $this->assertTrue($connector->isConfigured());
    }

    public function test_it_never_falls_back_to_yassen_credentials(): void
    {
        config(['services.allosharejle.token' => null]);

        $this->assertFalse((new AlloSharejleConnector())->isConfigured());
    }

    public function test_catalog_is_read_from_its_own_host_with_its_own_token(): void
    {
        Http::fake(['api.allosharejle.com/*' => Http::response([
            [
                'id' => 364, 'name' => 'PUBG 60 UC', 'price' => 0.85, 'base_price' => 0.9,
                'params' => ['Player ID'], 'category_name' => 'PUBG', 'category_img' => '',
                'available' => true, 'product_type' => 'package', 'parent_id' => 12,
                'qty_values' => null,
            ],
            [
                'id' => 400, 'name' => 'Jawaker Tokens', 'price' => 0.0001, 'base_price' => 0.0001,
                'params' => ['Player ID'], 'category_name' => 'Jawaker', 'category_img' => '',
                'available' => false, 'product_type' => 'amount', 'parent_id' => 13,
                'qty_values' => ['min' => '10000', 'max' => '1000000'],
            ],
        ], 200)]);

        $rows = (new AlloSharejleConnector())->fetchCatalog();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.allosharejle.com/client/api/products')
            && $r->header('api-token') === ['allo-token']);
        $this->assertCount(2, $rows);
        $this->assertSame('364', $rows[0]->externalId);
        $this->assertSame(0.85, $rows[0]->unitCost);
        $this->assertSame(1, $rows[0]->productTypeId);
        $this->assertSame(4, $rows[1]->productTypeId);
        $this->assertFalse($rows[1]->available);
    }

    public function test_place_order_reads_the_data_wrapped_reply(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'OK',
            'data' => [
                'order_id' => 'ID_9fffb0d849a45215',
                'status' => 'accept',
                'price' => 1.26048,
                'data' => ['playerId' => 'player-9'],
                'replay_api' => [['replay' => ['erg3eg']]],
            ],
        ], 200)]);

        $result = (new AlloSharejleConnector())->placeOrder($this->order(), $this->variation());

        $this->assertSame('ID_9fffb0d849a45215', $result->externalOrderId);
        $this->assertSame(SupplierOrderResult::COMPLETED, $result->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/client/api/newOrder/364/params')
            && str_contains($r->url(), 'order_uuid=uuid-allo-1')
            && str_contains($r->url(), 'playerId=player-9')
            && str_contains($r->url(), 'qty=2'));
    }

    public function test_check_order_maps_statuses(): void
    {
        $body = [];
        Http::fake(function () use (&$body) { return Http::response($body, 200); });

        foreach (['accept' => SupplierOrderResult::COMPLETED, 'wait' => SupplierOrderResult::PENDING, 'reject' => SupplierOrderResult::FAILED] as $status => $expected) {
            $body = [
                'status' => 'OK',
                'data' => [['order_id' => 'ID_1', 'status' => $status, 'replay_api' => []]],
            ];

            $order = $this->order();
            $order->external_order_id = 'ID_1';

            $this->assertSame($expected, (new AlloSharejleConnector())->checkOrder($order)->status, $status);
        }
    }

    public function test_a_final_error_code_fails_the_order_so_it_is_refunded(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ERROR', 'code' => 100, 'error' => true, 'message' => 'Insufficient balance'], 200)]);

        $result = (new AlloSharejleConnector())->placeOrder($this->order(), $this->variation());

        $this->assertSame(SupplierOrderResult::FAILED, $result->status);
        $this->assertNull($result->externalOrderId);
    }

    public function test_a_transient_error_code_is_retried_not_refunded(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ERROR', 'code' => 111, 'error' => true, 'message' => 'Try again after 1 minute'], 200)]);

        $this->expectException(SupplierApiException::class);

        (new AlloSharejleConnector())->placeOrder($this->order(), $this->variation());
    }
}
