<?php

namespace Tests\Feature\Suppliers;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\PartialCatalogAware;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\SupplierCategory;
use Tests\TestCase;

/**
 * Grouped supplier categories: `supplier_categories.group_as_single_product`
 * makes the sync maintain ONE product for the category with a variation per
 * supplier row, instead of a product per row.
 *
 * These hit the database (products, variations, the local category tree), so
 * they live in Feature rather than alongside the pure mapping tests in
 * tests/Unit/SupplierTest.php. DatabaseTransactions rolls everything back.
 */
class SupplierCatalogSyncGroupingTest extends TestCase
{
    private const SOURCE = 'test-grouped';
    private const CATEGORY = 'quota-bundles';

    // ------------------------------------------------------------------ tests

    public function test_grouped_category_imports_one_product_with_a_variation_per_row(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, true);

        $summary = (new SupplierCatalogSync())->sync($connector);

        $products = $this->products();
        $this->assertCount(1, $products, 'a grouped category must produce exactly one product');

        $product = $products->first();
        $this->assertSame('group:' . self::CATEGORY, $product->external_id);
        // Load-bearing: SupplierOrderFulfillment resolves the connector from these
        // two columns and silently skips placement when either is missing.
        $this->assertSame(self::SOURCE, $product->external_source);
        $this->assertSame(3, (int) $product->product_type_id);
        $this->assertSame('Internet Quota Bundles', $product->name);
        $this->assertSame(1, (int) $product->is_active);

        $variations = $this->variations($product);
        $this->assertCount(3, $variations);
        $this->assertSame(
            ['quota:5.5', 'quota:11', 'quota:45'],
            $variations->pluck('external_id')->all(),
            'dropdown order follows the feed via ht_pos'
        );
        $this->assertSame(['5.5 GB', '11 GB', '45 GB'], $variations->pluck('name')->all());

        // price = cost * (1 + profit%), the one formula, unchanged by grouping.
        $expected = ProductsVariation::computeSellingPrice(10.0, $product->effectiveProfitPercentage());
        $this->assertEqualsWithDelta($expected, (float) $variations->firstWhere('external_id', 'quota:11')->price, 0.001);

        $this->assertSame(3, $summary['created']);
    }

    public function test_turning_grouping_on_moves_existing_variations_and_retires_the_shells(): void
    {
        $connector = $this->connector($this->bundles());
        $supplierCategory = $this->enableCategory($connector, false);

        // First sync with grouping OFF — the behaviour that produced the six
        // separate "5.5 GB" / "11 GB" / "45 GB" products in the first place.
        (new SupplierCatalogSync())->sync($connector);
        $this->assertCount(3, $this->products());

        $before = $this->allVariations()->pluck('id', 'external_id');
        $shellIds = $this->products()->pluck('id');

        // An order placed against one of them, to prove history survives.
        $order = $this->orderFor($before['quota:11']);

        $supplierCategory->group_as_single_product = true;
        $supplierCategory->save();

        (new SupplierCatalogSync())->sync($connector);

        // The per-row shells are retired via supplier_status, not is_active — see
        // the class docblock's ownership table. An admin could still see and
        // re-activate a stray shell in the CMS; sellable() is what the storefront
        // (and this assertion) actually cares about.
        $notWithdrawn = $this->products()->where('supplier_status', '!=', Product::SUPPLIER_WITHDRAWN);
        $this->assertCount(1, $notWithdrawn, 'the per-row shells must not stay in the catalog');

        $product = $notWithdrawn->first();
        $this->assertSame('group:' . self::CATEGORY, $product->external_id);

        $after = $this->allVariations()->pluck('id', 'external_id');
        $this->assertSame(
            $before->all(),
            $after->all(),
            'variations must MOVE onto the grouped product, keeping their ids — orders.product_variation_id points at them'
        );
        $this->assertCount(3, $this->variations($product));

        $this->assertSame(
            (int) $before['quota:11'],
            (int) $order->fresh()->product_variation_id,
            'the existing order still resolves to a live variation'
        );

        foreach (Product::withoutGlobalScope('cms_draft_flag')->whereIn('id', $shellIds)->get() as $shell) {
            $this->assertSame(Product::SUPPLIER_WITHDRAWN, $shell->supplier_status, "shell {$shell->external_id} should be withdrawn");
        }
    }

    public function test_a_withdrawn_bundle_marks_only_its_own_variation_withdrawn(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        // 11 GB sells out and drops off the stock list entirely.
        $connector->catalog = [$this->dto('5.5', '5.5 GB', 6.0), $this->dto('45', '45 GB', 20.0)];
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->first();
        $this->assertSame(1, (int) $product->fresh()->is_active, 'is_active is admin-owned — withdrawal never touches it');

        $byId = $this->allVariations()->keyBy('external_id');
        $this->assertSame(Product::SUPPLIER_WITHDRAWN, $byId['quota:11']->supplier_status);
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $byId['quota:5.5']->supplier_status);
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $byId['quota:45']->supplier_status);
        // is_active stays on for all three — sellability is what changed, not ownership.
        $this->assertSame(1, (int) $byId['quota:11']->is_active);
    }

    public function test_grouped_product_goes_dark_and_comes_back_with_its_bundles(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        // Every bundle reports out of stock — still listed by the feed, just
        // unavailable, which is NOT the same as being withdrawn from it.
        $connector->catalog = array_map(
            fn (SupplierProduct $dto) => $this->dto(
                str_replace('quota:', '', $dto->externalId),
                $dto->name,
                $dto->unitCost,
                false
            ),
            $this->bundles()
        );
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->first()->fresh();
        $this->assertSame(Product::SUPPLIER_OUT_OF_STOCK, $product->supplier_status);
        $this->assertSame(1, (int) $product->is_active, 'is_active is admin-owned; plain unavailability never touches it');
        $this->assertFalse(
            Product::sellable()->whereKey($product->id)->exists(),
            'an out-of-stock grouped product must not be sellable, with no admin action needed'
        );

        $connector->catalog = $this->bundles();
        (new SupplierCatalogSync())->sync($connector);

        $product = $product->fresh();
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $product->supplier_status);
        $this->assertTrue(Product::sellable()->whereKey($product->id)->exists(), 'restocking un-hides it automatically');
    }

    public function test_an_unreachable_family_deactivates_nothing(): void
    {
        $connector = $this->connector($this->bundles(), true);
        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        // Endpoint down: the connector vouches for nothing this run.
        $connector->catalog = [];
        $connector->scopes = [];
        (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(1, (int) $this->products()->first()->fresh()->is_active);
        $this->assertCount(3, $this->allVariations()->where('is_active', 1));
    }

    public function test_a_hand_added_variation_on_a_grouped_product_is_never_touched(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->first();

        // An admin adds their own entry to the same dropdown in the CMS.
        $manual = new ProductsVariation();
        $manual->product_id = $product->id;
        $manual->slug = 'manual-bonus-bundle';
        $manual->price = 99.0;
        $manual->cost_price = 50.0;
        $manual->is_active = 1;
        $manual->cms_draft_flag = 0;
        $manual->save();

        $connector->catalog = [];
        (new SupplierCatalogSync())->sync($connector);

        $manual->refresh();
        $this->assertSame(1, (int) $manual->is_active, 'a variation with no external_id is the admin\'s, not the sync\'s');
        $this->assertEqualsWithDelta(99.0, (float) $manual->price, 0.001);

        // …and the product markup must not reprice it either.
        $product->profit_percentage = 42;
        $product->save();
        $product->recalculateSupplierPrices();
        $this->assertEqualsWithDelta(99.0, (float) $manual->fresh()->price, 0.001);
    }

    public function test_a_variation_hand_moved_onto_a_non_supplier_product_is_reclaimed(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, false);
        (new SupplierCatalogSync())->sync($connector);

        // What an admin did before grouping existed: drag an imported variation onto
        // a hand-made product to fake a dropdown. Orders for it can never be placed —
        // fulfill() needs the PARENT product's external_id.
        $manualProduct = $this->products()->firstWhere('external_id', 'quota:11');
        $manualProduct->external_source = null;
        $manualProduct->external_id = null;
        $manualProduct->save();

        $moved = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', 'quota:11')
            ->firstOrFail();

        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->firstWhere('external_id', 'group:' . self::CATEGORY);
        $variations = $this->variations($product);

        $this->assertCount(3, $variations, 'the stray variation must be taken back, not duplicated');
        $this->assertSame(
            (int) $moved->id,
            (int) $variations->firstWhere('external_id', 'quota:11')->id,
            'and taken back as the same row, so its orders still resolve'
        );
        $this->assertNotNull($variations->firstWhere('external_id', 'quota:11')->ht_pos);
    }

    public function test_ungrouped_category_still_produces_one_product_per_row(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, false);

        (new SupplierCatalogSync())->sync($connector);

        $this->assertCount(3, $this->products());
        foreach ($this->products() as $product) {
            $this->assertCount(1, $this->variations($product));
        }
    }

    // ---------------------------------------------------------- catalog ownership

    /**
     * The bug this pins: an admin's Active choice on a supplier row used to be
     * undone by the next sync — always, since the sync wrote `is_active` from
     * the feed on every run. Now it writes `is_active` once, at creation, and
     * never again; sellability is derived from `is_active` + `supplier_status`
     * at read time instead. See the class docblock's ownership table.
     */
    public function test_admin_is_active_is_never_written_after_create(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, false); // ungrouped: one product per row
        (new SupplierCatalogSync())->sync($connector);

        // Admin switches an AVAILABLE row off.
        $eleven = $this->products()->firstWhere('external_id', 'quota:11');
        $eleven->is_active = 0;
        $eleven->save();
        $this->variations($eleven)->first()->update(['is_active' => 0]);

        // 45 GB goes out of stock at the supplier.
        $connector->catalog = [
            $this->dto('5.5', '5.5 GB', 6.0),
            $this->dto('11', '11 GB', 10.0),
            $this->dto('45', '45 GB', 20.0, false),
        ];
        (new SupplierCatalogSync())->sync($connector);

        // Admin switches that OUT-OF-STOCK row on anyway.
        $fortyFive = $this->products()->firstWhere('external_id', 'quota:45');
        $fortyFive->is_active = 1;
        $fortyFive->save();
        $this->variations($fortyFive)->first()->update(['is_active' => 1]);

        // Re-sync: neither admin decision may move.
        (new SupplierCatalogSync())->sync($connector);

        $eleven = $eleven->fresh();
        $this->assertSame(0, (int) $eleven->is_active, 'admin turned an available row off — the sync must never turn it back on');

        $fortyFive = $fortyFive->fresh();
        $this->assertSame(1, (int) $fortyFive->is_active, 'admin turned an out-of-stock row on — the sync must never turn it back off');
        $this->assertSame(Product::SUPPLIER_OUT_OF_STOCK, $fortyFive->supplier_status);
        $this->assertFalse(
            Product::sellable()->whereKey($fortyFive->id)->exists(),
            'on but out of stock is still not sellable'
        );

        // Restock — sellable again with zero admin action.
        $connector->catalog = $this->bundles();
        (new SupplierCatalogSync())->sync($connector);

        $fortyFive = $fortyFive->fresh();
        $this->assertSame(1, (int) $fortyFive->is_active);
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $fortyFive->supplier_status);
        $this->assertTrue(Product::sellable()->whereKey($fortyFive->id)->exists());
    }

    /** Name/description/product_type_id are admin-owned the same way is_active is. */
    public function test_admin_name_description_and_type_survive_a_sync(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, false);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->firstWhere('external_id', 'quota:11');
        $variation = $this->variations($product)->first();

        $product->product_type_id = 2;
        $product->name = 'Admin Renamed Product';
        $product->description = 'Admin product description';
        $product->save();

        $variation->name = 'Admin Renamed Variation';
        $variation->description = 'Admin variation description';
        $variation->save();

        // The feed still reports its own name/type on the next run.
        (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(2, (int) $product->fresh()->product_type_id);
        $this->assertSame('Admin Renamed Product', $product->fresh()->name);
        $this->assertSame('Admin product description', $product->fresh()->description);
        $this->assertSame('Admin Renamed Variation', $variation->fresh()->name);
        $this->assertSame('Admin variation description', $variation->fresh()->description);
    }

    public function test_grouped_product_status_rolls_up_from_its_variations(): void
    {
        $connector = $this->connector($this->bundles());
        $this->enableCategory($connector, true);
        (new SupplierCatalogSync())->sync($connector);

        $product = $this->products()->first();
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $product->fresh()->supplier_status, 'any row available → available');

        // Every row out of stock, but still listed → out_of_stock, not withdrawn.
        $connector->catalog = array_map(
            fn (SupplierProduct $dto) => $this->dto(str_replace('quota:', '', $dto->externalId), $dto->name, $dto->unitCost, false),
            $this->bundles()
        );
        (new SupplierCatalogSync())->sync($connector);
        $this->assertSame(Product::SUPPLIER_OUT_OF_STOCK, $product->fresh()->supplier_status);

        // Every row drops off the feed entirely → withdrawn.
        $connector->catalog = [];
        (new SupplierCatalogSync())->sync($connector);
        $this->assertSame(Product::SUPPLIER_WITHDRAWN, $product->fresh()->supplier_status);

        // One size comes back — the product follows it straight back to available.
        $connector->catalog = [$this->dto('11', '11 GB', 10.0)];
        (new SupplierCatalogSync())->sync($connector);
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $product->fresh()->supplier_status);
    }

    // ---------------------------------------------------------------- helpers

    /** @return SupplierProduct[] */
    private function bundles(): array
    {
        return [
            $this->dto('5.5', '5.5 GB', 6.0),
            $this->dto('11', '11 GB', 10.0),
            $this->dto('45', '45 GB', 20.0),
        ];
    }

    private function dto(string $ref, string $name, float $cost, bool $available = true): SupplierProduct
    {
        return new SupplierProduct(
            externalId: 'quota:' . $ref,
            name: $name,
            categoryExternalId: self::CATEGORY,
            categoryName: 'Internet Quota Bundles',
            categoryImage: null,
            unitCost: $cost,
            available: $available,
            productTypeId: 3,
            qtyValues: ['min' => 1, 'max' => 1],
            externalType: 'quota',
        );
    }

    private function connector(array $catalog, bool $partial = false): FakeGroupedConnector
    {
        return $partial
            ? new FakePartialGroupedConnector(self::SOURCE, $catalog, ['quota:'])
            : new FakeGroupedConnector(self::SOURCE, $catalog);
    }

    /**
     * Categories are discovered disabled (the admin's opt-in), so enabling one is
     * a discovery pass followed by the CMS toggles — exactly what an admin does.
     */
    private function enableCategory(SupplierConnector $connector, bool $grouped): SupplierCategory
    {
        (new SupplierCatalogSync())->sync($connector, true);

        $supplierCategory = SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', self::SOURCE)
            ->where('external_id', self::CATEGORY)
            ->firstOrFail();

        $supplierCategory->import_enabled = true;
        $supplierCategory->group_as_single_product = $grouped;
        $supplierCategory->save();

        return $supplierCategory;
    }

    private function products()
    {
        return Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
            ->orderBy('id')
            ->get();
    }

    private function variations(Product $product)
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('product_id', $product->id)
            ->orderByRaw('ht_pos IS NULL, ht_pos')
            ->orderBy('id')
            ->get();
    }

    private function allVariations()
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->whereIn('product_id', $this->products()->pluck('id'))
            ->orderBy('id')
            ->get();
    }

    /** orders.users_id and orders.product_variation_id both have foreign keys. */
    private function orderFor(int $variationId): Order
    {
        $user = User::create([
            'username' => 'grouping_' . uniqid(),
            'email' => 'grouping_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variationId,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);
    }
}

/**
 * Minimal in-test supplier. Only fetchCatalog()/key() matter to the sync engine;
 * the ordering half of the contract is never reached here.
 */
class FakeGroupedConnector implements SupplierConnector
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

/** The multi-endpoint case: `scopes` is what the connector could actually reach. */
class FakePartialGroupedConnector extends FakeGroupedConnector implements PartialCatalogAware
{
    public function __construct(string $key, array $catalog = [], public ?array $scopes = null)
    {
        parent::__construct($key, $catalog);
    }

    public function catalogScopes(): ?array
    {
        return $this->scopes;
    }
}
