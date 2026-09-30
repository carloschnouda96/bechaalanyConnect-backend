<?php

namespace Tests\Feature;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Storefront LISTINGS show only products that can be bought — Product::scopeListable():
 * sellable AND at least one active, in-stock variation.
 *
 * A product the admin had created but not yet given a variation used to show "Buy Now"
 * on every list and open onto an empty "Coming Soon" page. The single-product route is
 * deliberately left alone: a direct link still renders that state.
 */
class ListableProductsTest extends TestCase
{
    public function test_category_and_subcategory_lists_skip_products_with_nothing_to_sell(): void
    {
        $subcategory = $this->subcategory();

        $good = $this->product($subcategory);
        $empty = $this->product($subcategory, variations: []);
        $inactive = $this->product($subcategory, variations: [['is_active' => 0]]);
        $outOfStock = $this->product($subcategory, variations: [['supplier_status' => Product::SUPPLIER_OUT_OF_STOCK]]);
        $mixed = $this->product($subcategory, variations: [['is_active' => 0], ['is_active' => 1]]);

        $ids = collect($this->getJson($this->subcategoryUrl($subcategory))->assertOk()->json('products'))->pluck('id');

        $this->assertTrue($ids->contains($good->id));
        $this->assertTrue($ids->contains($mixed->id), 'one buyable variation is enough');
        $this->assertFalse($ids->contains($empty->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($outOfStock->id));

        // Same rule when the subcategory is not a storefront level and its products are
        // listed on the category page itself.
        $subcategory->update(['show_products_in_category' => 1]);
        $ids = collect($this->getJson('/api/en/categories/' . $subcategory->category->slug)->assertOk()->json('products'))->pluck('id');

        $this->assertTrue($ids->contains($good->id));
        $this->assertFalse($ids->contains($empty->id));
    }

    public function test_search_skips_products_with_nothing_to_sell(): void
    {
        $subcategory = $this->subcategory();
        $term = 'Quibble' . uniqid();

        $good = $this->product($subcategory, name: $term . ' Good');
        $empty = $this->product($subcategory, name: $term . ' Empty', variations: []);

        $ids = collect($this->getJson('/api/en/search?name=' . urlencode($term))->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($good->id));
        $this->assertFalse($ids->contains($empty->id));
    }

    public function test_homepage_latest_products_skip_products_with_nothing_to_sell(): void
    {
        $subcategory = $this->subcategory();
        $empty = $this->product($subcategory, variations: []);

        $ids = collect($this->getJson('/api/en/home')->assertOk()->json('latest_products'))->pluck('id');

        $this->assertFalse($ids->contains($empty->id));
    }

    public function test_related_products_skip_products_with_nothing_to_sell(): void
    {
        $subcategory = $this->subcategory();
        $product = $this->product($subcategory);
        $relatedGood = $this->product($subcategory);
        $relatedEmpty = $this->product($subcategory, variations: []);

        foreach ([$relatedGood, $relatedEmpty] as $i => $related) {
            DB::table('related_product_product')->insert([
                'product_id' => $product->id,
                'other_product_id' => $related->id,
                'ht_pos' => $i,
            ]);
        }

        $ids = collect($this->getJson($this->productUrl($product, $subcategory))->assertOk()->json('product.related_products'))->pluck('id');

        $this->assertTrue($ids->contains($relatedGood->id));
        $this->assertFalse($ids->contains($relatedEmpty->id));
    }

    public function test_a_direct_link_to_an_empty_product_still_responds(): void
    {
        $subcategory = $this->subcategory();
        $empty = $this->product($subcategory, variations: []);

        $this->getJson($this->productUrl($empty, $subcategory))
            ->assertOk()
            ->assertJsonPath('product.id', $empty->id)
            ->assertJsonCount(0, 'product_variations');
    }

    // ---------------------------------------------------------------- helpers

    private function subcategory(): Subcategory
    {
        $suffix = uniqid();
        $category = Category::create(['slug' => 'lc-' . $suffix, 'is_active' => 1]);

        return Subcategory::create(['slug' => 'ls-' . $suffix, 'category_id' => $category->id, 'is_active' => 1]);
    }

    /** @param array<int, array<string, mixed>> $variations one entry per variation to create */
    private function product(Subcategory $subcategory, ?string $name = null, array $variations = [[]]): Product
    {
        $suffix = uniqid();

        $product = Product::create([
            'slug' => 'lp-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => 2,
            'is_active' => 1,
        ]);

        if ($name !== null) {
            $product->name = $name;
            $product->save();
        }

        foreach ($variations as $i => $overrides) {
            ProductsVariation::create(array_merge([
                'slug' => 'lv-' . $suffix . '-' . $i,
                'product_id' => $product->id,
                'price' => 5.00,
                'cost_price' => 2.50,
                'is_active' => 1,
                'supplier_status' => null,
            ], $overrides));
        }

        return $product;
    }

    private function subcategoryUrl(Subcategory $subcategory): string
    {
        return sprintf('/api/en/categories/%s/%s', $subcategory->category->slug, $subcategory->slug);
    }

    private function productUrl(Product $product, Subcategory $subcategory): string
    {
        return sprintf('/api/en/categories/%s/%s/%s', $subcategory->category->slug, $subcategory->slug, $product->slug);
    }
}
