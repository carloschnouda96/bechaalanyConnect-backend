<?php

namespace Tests\Feature\Suppliers;

use App\Category;
use App\FixedSetting;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Tests\TestCase;

/**
 * Profit % lives on the variation, so two sizes of one product can carry different
 * markups. An empty profit % falls back to Fixed Settings' default — there is no
 * product-level tier any more.
 */
class VariationProfitPercentageTest extends TestCase
{
    private function product(): Product
    {
        $suffix = uniqid();
        $category = Category::create(['slug' => 'cat-' . $suffix, 'is_active' => 1]);
        $subcategory = Subcategory::create(['slug' => 'sub-' . $suffix, 'category_id' => $category->id, 'is_active' => 1]);

        return Product::create([
            'slug' => 'prod-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => 2,
            'is_active' => 1,
            'external_source' => 'yassen',
            'external_id' => 'ext-' . $suffix,
        ]);
    }

    private function variation(Product $product, ?float $cost, float $price, array $extra = []): ProductsVariation
    {
        return ProductsVariation::create(array_merge([
            'slug' => 'var-' . uniqid(),
            'product_id' => $product->id,
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => 1,
            'external_id' => 'extv-' . uniqid(),
        ], $extra));
    }

    public function test_sibling_variations_carry_different_markups(): void
    {
        $product = $this->product();
        $small = $this->variation($product, 10.00, 1.00);
        $large = $this->variation($product, 10.00, 1.00);

        $small->update(['profit_percentage' => 20]);
        $large->update(['profit_percentage' => 5]);

        $this->assertEquals(12.00, (float) $small->fresh()->price);
        $this->assertEquals(10.50, (float) $large->fresh()->price);
    }

    public function test_an_empty_profit_falls_back_to_the_fixed_settings_default(): void
    {
        // FixedSetting::current() is cached in a static for the whole process, so the
        // transaction rollback won't undo this in memory — restore it by hand.
        $settings = FixedSetting::current();
        $original = $settings->default_profit_percentage;
        $settings->default_profit_percentage = 15;

        try {
            $variation = $this->variation($this->product(), 10.00, 1.00, ['profit_percentage' => 30]);
            $this->assertEquals(30.0, $variation->effectiveProfitPercentage());

            $variation->update(['profit_percentage' => null]);

            $fresh = $variation->fresh();
            $this->assertEquals(15.0, $fresh->effectiveProfitPercentage());
            $this->assertEquals(11.50, (float) $fresh->price, 'clearing profit % reprices at the default');
        } finally {
            $settings->default_profit_percentage = $original;
        }
    }

    public function test_a_profit_edit_on_a_variation_without_cost_keeps_its_price(): void
    {
        $variation = $this->variation($this->product(), null, 25.00);

        $variation->update(['profit_percentage' => 50]);

        $fresh = $variation->fresh();
        $this->assertEquals(50.00, (float) $fresh->profit_percentage);
        $this->assertEquals(25.00, (float) $fresh->price);
    }

    public function test_a_new_variation_with_cost_and_profit_but_no_price_is_priced(): void
    {
        $variation = ProductsVariation::create([
            'slug' => 'var-' . uniqid(),
            'product_id' => $this->product()->id,
            'cost_price' => 8.00,
            'profit_percentage' => 25,
            'is_active' => 1,
        ]);

        $this->assertEquals(10.00, (float) $variation->fresh()->price);
    }

    public function test_profit_percentage_stays_out_of_public_responses(): void
    {
        $variation = $this->variation($this->product(), 10.00, 1.00, ['profit_percentage' => 30]);

        $this->assertArrayNotHasKey('profit_percentage', $variation->fresh()->toArray());
        $this->assertArrayNotHasKey('cost_price', $variation->fresh()->toArray());
    }
}
