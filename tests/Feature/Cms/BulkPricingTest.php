<?php

namespace Tests\Feature\Cms;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Hellotreedigital\Cms\Models\Admin;
use Tests\TestCase;

/**
 * Bulk repricing must produce exactly what the single-product path produces, because
 * both are supposed to be the same formula: ProductsVariation::computeSellingPrice().
 * The failure this guards against is a second copy of the markup arithmetic drifting
 * from the one the supplier syncs use.
 */
class BulkPricingTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Pricing Admin';
        $admin->email = 'pricing_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function product(?string $source, float $cost, float $price, bool $supplierVariation = true): array
    {
        $suffix = uniqid();

        $category = Category::create(['slug' => 'cat-' . $suffix, 'is_active' => 1]);
        $subcategory = Subcategory::create(['slug' => 'sub-' . $suffix, 'category_id' => $category->id, 'is_active' => 1]);

        $product = Product::create([
            'slug' => 'prod-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => 2,
            'is_active' => 1,
            'external_source' => $source,
            'external_id' => $source ? 'ext-' . $suffix : null,
        ]);

        $variation = ProductsVariation::create([
            'slug' => 'var-' . $suffix,
            'product_id' => $product->id,
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => 1,
            'external_id' => ($source && $supplierVariation) ? 'extv-' . $suffix : null,
        ]);

        return [$product, $variation];
    }

    private function url(): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/catalog-pricing';
    }

    public function test_setting_profit_reprices_a_supplier_product_from_cost(): void
    {
        [$product, $variation] = $this->product('yassen', 5.00, 1.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => (string) $product->id, 'action' => 'set_profit', 'value' => 20])
            ->assertRedirect();

        $this->assertEquals(20.00, (float) $product->fresh()->profit_percentage);
        $this->assertEquals(
            ProductsVariation::computeSellingPrice(5.00, 20.0),
            (float) $variation->fresh()->price,
            'must match the shared formula exactly'
        );
        $this->assertEquals(6.00, (float) $variation->fresh()->price);
    }

    public function test_setting_profit_reprices_a_manual_product_that_has_a_cost(): void
    {
        [$product, $variation] = $this->product(null, 4.00, 99.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => (string) $product->id, 'action' => 'set_profit', 'value' => 50])
            ->assertRedirect();

        $this->assertEquals(6.00, (float) $variation->fresh()->price);
    }

    /**
     * A direct price write on a supplier product would be reverted by the next sync, so
     * it must be refused now rather than appearing to work and quietly undoing itself.
     */
    public function test_adjusting_price_refuses_supplier_products(): void
    {
        [$product, $variation] = $this->product('swift', 5.00, 10.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => (string) $product->id, 'action' => 'adjust_price', 'value' => 10])
            ->assertRedirect();

        $this->assertEquals(10.00, (float) $variation->fresh()->price, 'supplier price must be untouched');
    }

    public function test_adjusting_price_moves_manual_variations(): void
    {
        [$product, $variation] = $this->product(null, 4.00, 10.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => (string) $product->id, 'action' => 'adjust_price', 'value' => 10])
            ->assertRedirect();

        $this->assertEquals(11.00, (float) $variation->fresh()->price);
    }

    /** A hand-added variation on a supplier product is priced by a human, not the sync. */
    public function test_setting_profit_leaves_a_hand_added_variation_alone(): void
    {
        [$product, $supplierVariation] = $this->product('yassen', 5.00, 1.00);

        $manual = ProductsVariation::create([
            'slug' => 'manual-' . uniqid(),
            'product_id' => $product->id,
            'price' => 42.00,
            'cost_price' => 5.00,
            'is_active' => 1,
            'external_id' => null,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => (string) $product->id, 'action' => 'set_profit', 'value' => 20]);

        $this->assertEquals(6.00, (float) $supplierVariation->fresh()->price);
        $this->assertEquals(42.00, (float) $manual->fresh()->price,
            'recalculateSupplierPrices() deliberately skips variations with no external_id');
    }

    public function test_the_listing_previews_prices_without_writing(): void
    {
        [$product, $variation] = $this->product('yassen', 5.00, 1.00);

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url() . '?preview_profit=100&source=yassen')
            ->assertOk()
            ->assertSee('10.00', false);

        $this->assertEquals(1.00, (float) $variation->fresh()->price, 'preview must not write');
    }

    public function test_an_invalid_action_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['ids' => '1', 'action' => 'delete_everything', 'value' => 1])
            ->assertSessionHasErrors('action');
    }

    public function test_the_page_requires_an_admin(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
