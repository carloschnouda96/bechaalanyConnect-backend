<?php

namespace Tests\Feature\Suppliers;

use App\Category;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Subcategory;
use App\SupplierCategory;
use Tests\TestCase;

/**
 * A non-grouped supplier category mapped to a Category only (no Subcategory)
 * lists its products directly on the category page.
 *
 * `products.subcategory_id` is NOT NULL, so the sync still creates a
 * subcategory to hold them — flagged `show_products_in_category`, which the
 * storefront API treats as "not a level": left out of the category's
 * `subcategories` and its products returned under `products` instead.
 */
class SupplierCatalogDirectCategoryTest extends TestCase
{
    private const SOURCE = 'test-direct';
    private const CATEGORY = 'music-streaming';

    public function test_category_only_mapping_lists_products_directly_on_the_category(): void
    {
        $connector = $this->connector();
        $this->enableCategory($connector, false);

        (new SupplierCatalogSync())->sync($connector);

        $supplierCategory = $this->supplierCategory();
        $subcategory = Subcategory::withoutGlobalScope('cms_draft_flag')->findOrFail($supplierCategory->subcategory_id);
        $this->assertTrue($subcategory->show_products_in_category);

        $category = Category::withoutGlobalScope('cms_draft_flag')->findOrFail($supplierCategory->category_id);
        $response = $this->getJson("/api/en/categories/{$category->slug}")->assertOk();

        $this->assertSame([], $response->json('subcategories'), 'the hidden subcategory is not a storefront level');
        $this->assertEqualsCanonicalizing(
            ['Netflix', 'Anghami'],
            array_column($response->json('products'), 'name')
        );
        // The product URL still carries the (hidden) subcategory slug.
        $this->assertSame($subcategory->slug, $response->json('products.0.subcategory.slug'));

        // The subcategory's own page tells the storefront to redirect to the category.
        $this->getJson("/api/en/categories/{$category->slug}/{$subcategory->slug}")
            ->assertOk()
            ->assertJsonPath('show_products_in_category', true);
    }

    public function test_a_second_sync_reuses_the_hidden_subcategory(): void
    {
        $connector = $this->connector();
        $this->enableCategory($connector, false);

        (new SupplierCatalogSync())->sync($connector);
        $first = $this->supplierCategory()->subcategory_id;

        (new SupplierCatalogSync())->sync($connector);

        $this->assertSame($first, $this->supplierCategory()->subcategory_id);
        $this->assertSame(1, Subcategory::withoutGlobalScope('cms_draft_flag')
            ->where('category_id', $this->supplierCategory()->category_id)->count());
    }

    public function test_an_admin_picked_subcategory_keeps_the_category_subcategory_products_path(): void
    {
        $connector = $this->connector();
        $supplierCategory = $this->enableCategory($connector, false);

        $category = new Category();
        $category->slug = 'direct-test-cat-' . uniqid();
        $category->is_active = 1;
        $category->fill(['en' => ['title' => 'Streaming'], 'ar' => ['title' => 'Streaming']]);
        $category->save();

        $subcategory = new Subcategory();
        $subcategory->slug = 'direct-test-sub-' . uniqid();
        $subcategory->category_id = $category->id;
        $subcategory->is_active = 1;
        $subcategory->fill(['en' => ['title' => 'Music'], 'ar' => ['title' => 'Music']]);
        $subcategory->save();

        $supplierCategory->category_id = $category->id;
        $supplierCategory->subcategory_id = $subcategory->id;
        $supplierCategory->save();

        (new SupplierCatalogSync())->sync($connector);

        $this->assertFalse((bool) $subcategory->fresh()->show_products_in_category);
        $this->assertSame($subcategory->id, $this->supplierCategory()->subcategory_id);

        $response = $this->getJson("/api/en/categories/{$category->slug}")->assertOk();
        $this->assertSame([$subcategory->id], array_column($response->json('subcategories'), 'id'));
        $this->assertSame([], $response->json('products'));
    }

    public function test_a_grouped_category_keeps_a_visible_subcategory(): void
    {
        $connector = $this->connector();
        $this->enableCategory($connector, true);

        (new SupplierCatalogSync())->sync($connector);

        $subcategory = Subcategory::withoutGlobalScope('cms_draft_flag')->findOrFail($this->supplierCategory()->subcategory_id);
        $this->assertFalse((bool) $subcategory->show_products_in_category);
    }

    public function test_unsellable_products_are_not_listed_on_the_category(): void
    {
        $connector = $this->connector([
            $this->dto('netflix', 'Netflix', 8.0),
            $this->dto('anghami', 'Anghami', 19.8, available: false),
        ]);
        $this->enableCategory($connector, false);

        (new SupplierCatalogSync())->sync($connector);

        $category = Category::withoutGlobalScope('cms_draft_flag')->findOrFail($this->supplierCategory()->category_id);
        $response = $this->getJson("/api/en/categories/{$category->slug}")->assertOk();

        $this->assertSame(['Netflix'], array_column($response->json('products'), 'name'));
    }

    // ---------------------------------------------------------------- helpers

    /** @param SupplierProduct[]|null $catalog */
    private function connector(?array $catalog = null): SupplierConnector
    {
        $catalog ??= [$this->dto('netflix', 'Netflix', 8.0), $this->dto('anghami', 'Anghami', 19.8)];

        return new class(self::SOURCE, $catalog) implements SupplierConnector {
            public function __construct(private string $key, public array $catalog)
            {
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
        };
    }

    private function dto(string $ref, string $name, float $cost, bool $available = true): SupplierProduct
    {
        return new SupplierProduct(
            externalId: $ref,
            name: $name,
            categoryExternalId: self::CATEGORY,
            categoryName: 'Music Streaming',
            categoryImage: null,
            unitCost: $cost,
            available: $available,
            productTypeId: 1,
            qtyValues: null,
            externalType: 'Package',
        );
    }

    /** Discovery pass, then the admin's CMS toggles. */
    private function enableCategory(SupplierConnector $connector, bool $grouped): SupplierCategory
    {
        (new SupplierCatalogSync())->sync($connector, true);

        $supplierCategory = $this->supplierCategory();
        $supplierCategory->import_enabled = true;
        $supplierCategory->group_as_single_product = $grouped;
        $supplierCategory->save();

        return $supplierCategory;
    }

    private function supplierCategory(): SupplierCategory
    {
        return SupplierCategory::withoutGlobalScope('cms_draft_flag')
            ->where('source', self::SOURCE)
            ->where('external_id', self::CATEGORY)
            ->firstOrFail();
    }
}
