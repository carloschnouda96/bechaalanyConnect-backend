<?php

namespace Tests\Feature;

use App\Category;
use App\Models\User;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Every public storefront query (category listing, search, single product —
 * variations and related products — and order placement) must agree on what
 * "sellable" means: `is_active = 1 AND (supplier_status IS NULL OR = available)`
 * — see Product::scopeSellable() / ProductsVariation::scopeSellable() and the
 * ownership table in SupplierCatalogSync's class docblock.
 *
 * Unit coverage of the sync writing supplier_status correctly already lives in
 * SupplierCatalogSyncGroupingTest; this file is the read side — that every route
 * that lists or serves a product actually applies the scope.
 */
class StorefrontSellabilityTest extends TestCase
{
    use CreatesCatalog;

    // ------------------------------------------------------------------ tests

    public function test_category_listing_hides_non_sellable_products(): void
    {
        $subcategory = $this->subcategory();

        $platform = $this->product($subcategory, null);
        $available = $this->product($subcategory, Product::SUPPLIER_AVAILABLE);
        $outOfStock = $this->product($subcategory, Product::SUPPLIER_OUT_OF_STOCK);
        $withdrawn = $this->product($subcategory, Product::SUPPLIER_WITHDRAWN);
        $inactive = $this->product($subcategory, null, active: false);

        $response = $this->getJson($this->categoryUrl($subcategory));
        $response->assertOk();

        $ids = collect($response->json('products'))->pluck('id');

        $this->assertTrue($ids->contains($platform->id), 'a plain platform product (NULL status) must be listed');
        $this->assertTrue($ids->contains($available->id), 'a supplier product currently in stock must be listed');
        $this->assertFalse($ids->contains($outOfStock->id));
        $this->assertFalse($ids->contains($withdrawn->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_search_hides_non_sellable_products(): void
    {
        $subcategory = $this->subcategory();
        $term = 'Zorblax' . uniqid();

        $visible = $this->product($subcategory, Product::SUPPLIER_AVAILABLE, name: $term . ' Visible');
        $hidden = $this->product($subcategory, Product::SUPPLIER_OUT_OF_STOCK, name: $term . ' Hidden');

        $response = $this->getJson('/api/en/search?name=' . urlencode($term));
        $response->assertOk();

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_single_product_hides_non_sellable_variations(): void
    {
        $subcategory = $this->subcategory();
        $product = $this->product($subcategory, null);
        $activeVariation = $this->firstVariation($product);

        $outOfStock = $this->variationFor($product, Product::SUPPLIER_OUT_OF_STOCK);
        $withdrawn = $this->variationFor($product, Product::SUPPLIER_WITHDRAWN);
        $inactive = $this->variationFor($product, null, active: false);

        $response = $this->getJson($this->productUrl($product, $subcategory));
        $response->assertOk();

        $ids = collect($response->json('product_variations'))->pluck('id');
        $this->assertTrue($ids->contains($activeVariation->id));
        $this->assertFalse($ids->contains($outOfStock->id));
        $this->assertFalse($ids->contains($withdrawn->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_single_product_hides_non_sellable_related_products(): void
    {
        $subcategory = $this->subcategory();
        $product = $this->product($subcategory, null);

        $visibleRelated = $this->product($subcategory, Product::SUPPLIER_AVAILABLE);
        $hiddenRelated = $this->product($subcategory, Product::SUPPLIER_WITHDRAWN);
        $product->related_products()->attach([$visibleRelated->id, $hiddenRelated->id]);

        $response = $this->getJson($this->productUrl($product, $subcategory));
        $response->assertOk();

        $ids = collect($response->json('product.related_products'))->pluck('id');
        $this->assertTrue($ids->contains($visibleRelated->id));
        $this->assertFalse($ids->contains($hiddenRelated->id));
    }

    public function test_homepage_latest_products_hides_non_sellable_products(): void
    {
        $subcategory = $this->subcategory();
        $visible = $this->product($subcategory, Product::SUPPLIER_AVAILABLE);
        $hidden = $this->product($subcategory, Product::SUPPLIER_OUT_OF_STOCK);

        $response = $this->getJson('/api/en/home');
        $response->assertOk();

        $ids = collect($response->json('latest_products'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_save_order_refuses_a_non_sellable_variation(): void
    {
        $user = $this->approvedUser();
        $variation = $this->createVariation(active: true, supplierStatus: Product::SUPPLIER_OUT_OF_STOCK);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'product_unavailable');
    }

    public function test_save_order_accepts_a_sellable_supplier_variation(): void
    {
        $user = $this->approvedUser(20.00);
        $variation = $this->createVariation(
            price: 5.00,
            productTypeId: 2,
            active: true,
            supplierStatus: Product::SUPPLIER_AVAILABLE
        );

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 1,
        ]);

        // Not the sellability rejection this file exists to pin — placement may
        // still fail for other reasons (no supplier configured for
        // 'test-supplier'), but that is FulfillSupplierOrderJob's business, not
        // saveOrder's. The order row itself must be created either way.
        $response->assertJsonMissingPath('code');
        $this->assertDatabaseHas('orders', [
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function subcategory(): Subcategory
    {
        $suffix = uniqid();

        $category = Category::create([
            'slug' => 'cat-' . $suffix,
            'is_active' => 1,
        ]);

        return Subcategory::create([
            'slug' => 'sub-' . $suffix,
            'category_id' => $category->id,
            'is_active' => 1,
        ]);
    }

    private function product(
        Subcategory $subcategory,
        ?string $supplierStatus,
        bool $active = true,
        ?string $name = null
    ): Product {
        $suffix = uniqid();

        $product = Product::create([
            'slug' => 'prod-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => 2,
            'is_active' => $active ? 1 : 0,
            'external_source' => $supplierStatus !== null ? 'test-supplier' : null,
            'external_id' => $supplierStatus !== null ? 'ext-' . $suffix : null,
            'supplier_status' => $supplierStatus,
        ]);

        if ($name !== null) {
            $product->name = $name;
            $product->save();
        }

        ProductsVariation::create([
            'slug' => 'var-' . $suffix,
            'product_id' => $product->id,
            'price' => 5.00,
            'cost_price' => 2.50,
            'is_active' => $active ? 1 : 0,
            'supplier_status' => $supplierStatus,
        ]);

        return $product;
    }

    private function variationFor(Product $product, ?string $supplierStatus, bool $active = true): ProductsVariation
    {
        return ProductsVariation::create([
            'slug' => 'var-' . uniqid(),
            'product_id' => $product->id,
            'price' => 7.00,
            'cost_price' => 3.50,
            'is_active' => $active ? 1 : 0,
            'supplier_status' => $supplierStatus,
        ]);
    }

    private function firstVariation(Product $product): ProductsVariation
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function approvedUser(float $creditsBalance = 0): User
    {
        return User::create([
            'username' => 'buyer_' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $creditsBalance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function categoryUrl(Subcategory $subcategory): string
    {
        return sprintf('/api/en/categories/%s/%s', $subcategory->category->slug, $subcategory->slug);
    }

    private function productUrl(Product $product, Subcategory $subcategory): string
    {
        return sprintf(
            '/api/en/categories/%s/%s/%s',
            $subcategory->category->slug,
            $subcategory->slug,
            $product->slug
        );
    }
}
