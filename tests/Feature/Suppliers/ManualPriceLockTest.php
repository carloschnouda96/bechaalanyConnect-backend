<?php

namespace Tests\Feature\Suppliers;

use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\SupplierCategory;
use Tests\TestCase;

/**
 * `products_variations.price` used to have one writer: the sync, which recomputed
 * `cost_price x profit%` into it on every run and on every profit-% edit — so an
 * admin's direct edit "re-showed the price imported" a short time later. This pins
 * the fix: editing Price locks it (`manual_price`, set by
 * App\Observers\ProductsVariationObserver), and once locked neither the sync nor
 * a profit-% edit (Product::recalculateSupplierPrices()) writes Price again —
 * Cost price keeps syncing regardless.
 */
class ManualPriceLockTest extends TestCase
{
    private const SOURCE = 'test-manual-price';
    private const CATEGORY = 'manual-price-bundles';

    public function test_editing_price_locks_it_and_the_next_sync_updates_cost_only(): void
    {
        $connector = $this->connector([$this->dto('1', 'Bundle One', 10.0)]);
        $this->enableCategory($connector);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->product();
        $variation = $this->variation($product);

        $initialPrice = ProductsVariation::computeSellingPrice(10.0, $product->effectiveProfitPercentage());
        $this->assertEqualsWithDelta($initialPrice, (float) $variation->price, 0.001);
        $this->assertFalse((bool) $variation->manual_price);

        // The CMS "Products Variation" edit form does a plain save — not wrapped
        // in ProductsVariation::applyingSystemPricing() — so this is exactly what
        // an admin typing a new Price does.
        $variation->price = 19.99;
        $variation->save();

        $variation->refresh();
        $this->assertTrue((bool) $variation->manual_price, 'editing price directly must lock it');
        $this->assertEqualsWithDelta(19.99, (float) $variation->price, 0.001);

        // Supplier cost changes on the next sync.
        $connector->catalog = [$this->dto('1', 'Bundle One', 15.0)];
        (new SupplierCatalogSync())->sync($connector);

        $variation->refresh();
        $this->assertEqualsWithDelta(15.0, (float) $variation->cost_price, 0.001, 'cost must keep syncing');
        $this->assertEqualsWithDelta(15.0, (float) $variation->external_price, 0.001);
        $this->assertEqualsWithDelta(19.99, (float) $variation->price, 0.001, 'a locked price must survive the sync');
        $this->assertTrue((bool) $variation->manual_price);
    }

    public function test_profit_percentage_edit_reprices_only_the_unlocked_variations(): void
    {
        $connector = $this->connector([
            $this->dto('1', 'Bundle One', 10.0),
            $this->dto('2', 'Bundle Two', 10.0),
        ]);
        $this->enableCategory($connector);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->product();
        $locked = $this->variationByExternalId($product, 'quota:1');
        $unlocked = $this->variationByExternalId($product, 'quota:2');

        $locked->price = 50.00;
        $locked->save();
        $this->assertTrue((bool) $locked->fresh()->manual_price);

        $product->profit_percentage = 30;
        $product->save(); // ProductObserver -> recalculateSupplierPrices()

        $expected = ProductsVariation::computeSellingPrice(10.0, 30);
        $this->assertEqualsWithDelta(50.00, (float) $locked->fresh()->price, 0.001, 'a locked price must survive a profit% edit');
        $this->assertEqualsWithDelta($expected, (float) $unlocked->fresh()->price, 0.001, 'the unlocked sibling still reprices');
    }

    // ------------------------------------------------------------------ helpers

    private function dto(string $ref, string $name, float $cost): SupplierProduct
    {
        return new SupplierProduct(
            externalId: 'quota:' . $ref,
            name: $name,
            categoryExternalId: self::CATEGORY,
            categoryName: 'Manual Price Bundles',
            categoryImage: null,
            unitCost: $cost,
            available: true,
            productTypeId: 3,
            qtyValues: ['min' => 1, 'max' => 1],
            externalType: 'quota',
        );
    }

    private function connector(array $catalog): FakeManualPriceConnector
    {
        return new FakeManualPriceConnector(self::SOURCE, $catalog);
    }

    private function enableCategory(SupplierConnector $connector): SupplierCategory
    {
        (new SupplierCatalogSync())->sync($connector, true);

        $supplierCategory = SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', self::SOURCE)
            ->where('external_id', self::CATEGORY)
            ->firstOrFail();

        $supplierCategory->import_enabled = true;
        $supplierCategory->group_as_single_product = true;
        $supplierCategory->save();

        return $supplierCategory;
    }

    private function product(): Product
    {
        return Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
            ->firstOrFail();
    }

    private function variation(Product $product): ProductsVariation
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function variationByExternalId(Product $product, string $externalId): ProductsVariation
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('product_id', $product->id)
            ->where('external_id', $externalId)
            ->firstOrFail();
    }
}

/** Minimal in-test supplier. Only fetchCatalog()/key() matter to the sync engine. */
class FakeManualPriceConnector implements SupplierConnector
{
    public function __construct(
        private string $key,
        public array $catalog = [],
    ) {
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
