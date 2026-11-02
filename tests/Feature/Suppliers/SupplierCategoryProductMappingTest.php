<?php

namespace Tests\Feature\Suppliers;

use App\Category;
use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Connectors\OneXPanelConnector;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Subcategory;
use App\SupplierCategory;
use Hellotreedigital\Cms\Models\Admin;
use Tests\TestCase;

/**
 * `supplier_categories.product_id`: import a supplier category's rows as
 * variations of a product the admin created (1xpanel "Instagram - Likes (USA)"
 * → the admin's "Likes" product), instead of a product per row.
 *
 * Runs as the real '1xpanel' key so SupplierOrderFulfillment resolves the fake
 * connector through the registry, exactly as it would in production.
 */
class SupplierCategoryProductMappingTest extends TestCase
{
    private const SOURCE = '1xpanel';
    private const LIKES = 'test-instagram-likes-usa';
    private const FOLLOWERS_ARAB = 'test-instagram-followers-arab';
    private const FOLLOWERS_TURKISH = 'test-instagram-followers-turkish';

    private Subcategory $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = uniqid();
        $category = Category::create(['slug' => 'social-' . $suffix, 'is_active' => 1]);
        $this->instagram = Subcategory::create(['slug' => 'instagram-' . $suffix, 'category_id' => $category->id, 'is_active' => 1]);
    }

    public function test_mapped_categories_import_as_variations_of_the_admin_products(): void
    {
        $likes = $this->adminProduct('Likes');
        $followers = $this->adminProduct('Followers');
        $connector = $this->connector($this->catalog());

        $this->mapCategory($connector, self::LIKES, $likes);
        $this->mapCategory($connector, self::FOLLOWERS_ARAB, $followers);
        $this->mapCategory($connector, self::FOLLOWERS_TURKISH, $followers);

        $summary = (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(0, $summary['errors']);
        $this->assertSame(4, $summary['created']);
        $this->assertSame(0, Product::withoutGlobalScope('cms_draft_flag')->where('external_source', self::SOURCE)->count(),
            'a mapped category must not create any product of its own');

        $this->assertSame(['svc-likes-1'], $this->variations($likes)->pluck('external_id')->all());
        $this->assertSame(['svc-arab-1', 'svc-arab-2', 'svc-turk-1'], $this->variations($followers)->pluck('external_id')->all(),
            'several supplier categories can share one product');

        foreach ($this->variations($followers) as $variation) {
            $this->assertSame(self::SOURCE, $variation->external_source, 'the supplier travels with the variation');
            $this->assertSame(Product::SUPPLIER_AVAILABLE, $variation->supplier_status);
            $this->assertGreaterThan(0, (float) $variation->price);
        }

        // The admin's product is theirs: nothing about it is written by the sync.
        $followers->refresh();
        $this->assertSame('Followers', $followers->name);
        $this->assertNull($followers->external_source);
        $this->assertNull($followers->supplier_status);
        $this->assertSame($this->instagram->id, (int) $followers->subcategory_id);
        $this->assertSame(Product::TYPE_SOCIAL, (int) $followers->product_type_id);
    }

    public function test_a_row_dropped_from_the_feed_is_withdrawn_alone(): void
    {
        $followers = $this->adminProduct('Followers');
        $connector = $this->connector($this->catalog());
        $this->mapCategory($connector, self::FOLLOWERS_ARAB, $followers);
        (new SupplierCatalogSync())->sync($connector);

        $connector->catalog = array_values(array_filter($this->catalog(), fn ($dto) => $dto->externalId !== 'svc-arab-2'));
        (new SupplierCatalogSync())->sync($connector);

        $status = $this->variations($followers)->pluck('supplier_status', 'external_id');
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $status['svc-arab-1']);
        $this->assertSame(Product::SUPPLIER_WITHDRAWN, $status['svc-arab-2']);
    }

    public function test_disabling_a_mapped_category_withdraws_its_variations(): void
    {
        $followers = $this->adminProduct('Followers');
        $connector = $this->connector($this->catalog());
        $supplierCategory = $this->mapCategory($connector, self::FOLLOWERS_ARAB, $followers);
        (new SupplierCatalogSync())->sync($connector);

        $supplierCategory->import_enabled = false;
        $supplierCategory->save();
        (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(
            [Product::SUPPLIER_WITHDRAWN],
            $this->variations($followers)->pluck('supplier_status')->unique()->values()->all()
        );
    }

    public function test_mapping_moves_existing_rows_and_unmapping_moves_them_back(): void
    {
        $connector = $this->connector($this->catalog());
        $supplierCategory = $this->mapCategory($connector, self::LIKES, null);

        // Unmapped first: one product per row, the pre-feature behaviour.
        (new SupplierCatalogSync())->sync($connector);
        $shell = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
            ->where('external_id', 'svc-likes-1')
            ->firstOrFail();
        $variationId = ProductsVariation::withoutGlobalScope('cms_draft_flag')->where('product_id', $shell->id)->value('id');

        $likes = $this->adminProduct('Likes');
        $supplierCategory->product_id = $likes->id;
        $supplierCategory->save();
        (new SupplierCatalogSync())->sync($connector);

        $moved = $this->variations($likes);
        $this->assertSame([(int) $variationId], $moved->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'moved, not duplicated — the id (and every order on it) survives');
        $this->assertSame(Product::SUPPLIER_WITHDRAWN, $shell->fresh()->supplier_status, 'the old per-row shell retires');

        $supplierCategory->product_id = null;
        $supplierCategory->save();
        (new SupplierCatalogSync())->sync($connector);

        $this->assertCount(0, $this->variations($likes));
        $this->assertSame((int) $shell->id, (int) ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variationId)->product_id);
        $this->assertSame(Product::SUPPLIER_AVAILABLE, $shell->fresh()->supplier_status);
    }

    public function test_mapping_wins_over_grouping_and_retires_the_grouped_product(): void
    {
        $connector = $this->connector($this->catalog());
        $supplierCategory = $this->mapCategory($connector, self::FOLLOWERS_ARAB, null);
        $supplierCategory->group_as_single_product = true;
        $supplierCategory->save();
        (new SupplierCatalogSync())->sync($connector);

        $grouped = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
            ->where('external_id', SupplierCatalogSync::groupExternalId($supplierCategory))
            ->firstOrFail();
        $ids = $this->variations($grouped)->pluck('id')->all();

        $followers = $this->adminProduct('Followers');
        $supplierCategory->product_id = $followers->id; // grouping left ticked
        $supplierCategory->save();
        (new SupplierCatalogSync())->sync($connector);

        $this->assertSame($ids, $this->variations($followers)->pluck('id')->all());
        $this->assertCount(0, $this->variations($grouped));
        $this->assertSame(Product::SUPPLIER_WITHDRAWN, $grouped->fresh()->supplier_status);
    }

    public function test_a_supplier_owned_target_is_refused(): void
    {
        $target = $this->adminProduct('Someone else\'s');
        $target->external_source = 'swift';
        $target->external_id = 'svc-999';
        $target->save();

        $connector = $this->connector($this->catalog());
        $this->mapCategory($connector, self::LIKES, $target);

        $summary = (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(1, $summary['errors']);
        $this->assertCount(0, $this->variations($target));
    }

    public function test_a_product_type_mismatch_is_refused(): void
    {
        $codes = $this->adminProduct('Gift cards', Product::TYPE_CODE);
        $connector = $this->connector($this->catalog());
        $this->mapCategory($connector, self::LIKES, $codes);

        $summary = (new SupplierCatalogSync())->sync($connector);

        $this->assertSame(1, $summary['errors']);
        $this->assertCount(0, $this->variations($codes));
    }

    public function test_an_order_for_a_mapped_variation_is_placed_at_the_supplier(): void
    {
        $likes = $this->adminProduct('Likes');
        $connector = $this->connector($this->catalog());
        $this->mapCategory($connector, self::LIKES, $likes);
        (new SupplierCatalogSync())->sync($connector);

        $this->app->instance(OneXPanelConnector::class, $connector);

        $order = $this->orderFor((int) $this->variations($likes)->first()->id);

        $this->assertTrue(SupplierOrderFulfillment::isExternal($order));

        app(SupplierOrderFulfillment::class)->fulfill($order);

        $this->assertSame(1, $connector->placed, 'the parent product has no external_source; the variation\'s own must be used');
        $this->assertSame(self::SOURCE, $order->fresh()->external_source);
        $this->assertSame('fake-1', $order->fresh()->external_order_id);
    }

    public function test_the_supplier_imports_page_shows_and_edits_the_product_mapping(): void
    {
        $likes = $this->adminProduct('Likes');
        $supplierCategory = $this->mapCategory($this->connector($this->catalog()), self::LIKES, $likes);

        $admin = new Admin();
        $admin->name = 'Mapping Admin';
        $admin->email = 'mapping_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();
        $admin->refresh();

        $prefix = config('hellotree.cms_route_prefix');

        $this->actingAs($admin, 'admin')
            ->get('/' . $prefix . '/supplier-categories?per_page=100&search=' . urlencode('Likes (USA)'))
            ->assertOk()
            ->assertSee('/' . $prefix . '/products/' . $likes->id, false)
            ->assertSee('Likes');

        $this->actingAs($admin, 'admin')
            ->get('/' . $prefix . '/supplier-categories/' . $supplierCategory->id . '/edit')
            ->assertOk()
            ->assertSee('name="product_id"', false);
    }

    // ---------------------------------------------------------------- helpers

    /** @return SupplierProduct[] */
    private function catalog(): array
    {
        return [
            $this->dto('svc-likes-1', 'Instagram Likes [USA]', self::LIKES, 'Instagram - Likes (USA)'),
            $this->dto('svc-arab-1', 'Instagram Followers [Arab] HQ', self::FOLLOWERS_ARAB, 'Instagram - Followers (Arab)'),
            $this->dto('svc-arab-2', 'Instagram Followers [Arab] Real', self::FOLLOWERS_ARAB, 'Instagram - Followers (Arab)'),
            $this->dto('svc-turk-1', 'Instagram Followers [Turkish]', self::FOLLOWERS_TURKISH, 'Instagram - Followers (Turkish)'),
        ];
    }

    private function dto(string $id, string $name, string $category, string $categoryName): SupplierProduct
    {
        return new SupplierProduct(
            externalId: $id,
            name: $name,
            categoryExternalId: $category,
            categoryName: $categoryName,
            categoryImage: null,
            unitCost: 0.0021,
            available: true,
            productTypeId: Product::TYPE_SOCIAL,
            qtyValues: ['min' => 100, 'max' => 10000],
            externalType: 'Default',
        );
    }

    private function connector(array $catalog): FakeMappedConnector
    {
        return new FakeMappedConnector(self::SOURCE, $catalog);
    }

    private function adminProduct(string $name, int $type = Product::TYPE_SOCIAL): Product
    {
        $product = Product::create([
            'slug' => 'mapped-' . uniqid(),
            'subcategory_id' => $this->instagram->id,
            'product_type_id' => $type,
            'is_active' => 1,
        ]);
        $product->name = $name;
        $product->save();

        return $product;
    }

    /** Discovery pass, then the CMS edits an admin makes on the Supplier imports page. */
    private function mapCategory(SupplierConnector $connector, string $externalId, ?Product $product): SupplierCategory
    {
        (new SupplierCatalogSync())->sync($connector, true);

        $supplierCategory = SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', self::SOURCE)
            ->where('external_id', $externalId)
            ->firstOrFail();

        $supplierCategory->import_enabled = true;
        // 1xpanel categories are discovered grouped; pin the plain per-row mode so
        // each test states the one mode it is about.
        $supplierCategory->group_as_single_product = false;
        $supplierCategory->product_id = $product ? $product->id : null;
        $supplierCategory->save();

        return $supplierCategory;
    }

    private function variations(Product $product)
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('product_id', $product->id)
            ->orderByRaw('ht_pos IS NULL, ht_pos')
            ->orderBy('id')
            ->get();
    }

    private function orderFor(int $variationId): Order
    {
        $user = User::create([
            'username' => 'mapped_' . uniqid(),
            'email' => 'mapped_' . uniqid() . '@example.test',
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
            'quantity' => 1000,
            'total_price' => 10,
            'recipient_user' => 'https://instagram.com/example',
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }
}

class FakeMappedConnector implements SupplierConnector
{
    public int $placed = 0;

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
        $this->placed++;

        return new SupplierOrderResult(externalOrderId: 'fake-' . $this->placed, status: SupplierOrderResult::PENDING, raw: []);
    }

    public function checkOrder(Order $order): SupplierOrderResult
    {
        return new SupplierOrderResult(externalOrderId: (string) $order->external_order_id, status: SupplierOrderResult::PENDING, raw: []);
    }

    public function balance(): ?float
    {
        return null;
    }
}
