<?php

namespace Tests\Feature\Cms;

use App\ProductsVariation;
use App\Services\Cms\VariationPricing;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The variation form's one pricing question ("profit % on cost" or "fixed price"),
 * applied after the vendor save by Cms\VariationFormController.
 */
class VariationFormPricingTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Form Admin';
        $admin->email = 'form_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function url(string $path = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/products-variations' . $path;
    }

    /** @param array<string, mixed> $row price/cost_price/profit_percentage/manual_price */
    private function variation(array $row): ProductsVariation
    {
        $variation = $this->createVariation();
        DB::table('products_variations')->where('id', $variation->id)->update($row);

        return $variation->fresh();
    }

    /** Everything the vendor form posts, overridden by $fields. */
    private function payload(ProductsVariation $variation, array $fields): array
    {
        return array_merge([
            'product_id' => $variation->product_id,
            'is_active' => 'on',
            'price' => (string) $variation->price,
            'cost_price' => (string) $variation->cost_price,
            'profit_percentage' => $variation->profit_percentage === null ? '' : (string) $variation->profit_percentage,
            'unit_amount' => '',
            'en' => ['name' => 'Name', 'description' => '', 'unit_label' => ''],
            'ar' => ['name' => 'اسم', 'description' => '', 'unit_label' => ''],
            'draft_cms_field' => 0,
        ], $fields);
    }

    private function save(ProductsVariation $variation, array $fields)
    {
        return $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $variation->id), $this->payload($variation, $fields));
    }

    public function test_fixed_mode_sets_and_locks_the_price(): void
    {
        $variation = $this->variation(['price' => 10, 'cost_price' => 5, 'profit_percentage' => null, 'manual_price' => 0]);

        $this->save($variation, ['pricing_mode' => 'fixed', 'price' => '12'])->assertOk();

        $variation->refresh();
        $this->assertSame(12.0, (float) $variation->price);
        $this->assertTrue($variation->manual_price);
    }

    public function test_choosing_fixed_without_changing_the_number_still_locks_it(): void
    {
        $variation = $this->variation(['price' => 10, 'cost_price' => 5, 'profit_percentage' => 100, 'manual_price' => 0]);

        $this->save($variation, ['pricing_mode' => 'fixed'])->assertOk();

        $variation->refresh();
        $this->assertSame(10.0, (float) $variation->price);
        $this->assertTrue($variation->manual_price);
    }

    /**
     * The live case that motivated the form: profit 3% on a $10 cost, but a price
     * locked at $11. Switching to % without touching either number used to change
     * nothing, because neither field was dirty.
     */
    public function test_switching_back_to_percent_unlocks_and_reprices(): void
    {
        $variation = $this->variation(['price' => 11, 'cost_price' => 10, 'profit_percentage' => 3, 'manual_price' => 1]);

        $this->save($variation, ['pricing_mode' => 'percent'])->assertOk();

        $variation->refresh();
        $this->assertSame(10.3, (float) $variation->price);
        $this->assertFalse($variation->manual_price);
    }

    public function test_percent_mode_follows_a_new_cost(): void
    {
        $variation = $this->variation(['price' => 7.5, 'cost_price' => 5, 'profit_percentage' => 50, 'manual_price' => 0]);

        $this->save($variation, ['pricing_mode' => 'percent', 'cost_price' => '8'])->assertOk();

        $this->assertSame(12.0, (float) $variation->fresh()->price);
    }

    public function test_a_blank_percent_uses_the_default(): void
    {
        $variation = $this->variation(['price' => 99, 'cost_price' => 4, 'profit_percentage' => 20, 'manual_price' => 1]);

        $this->save($variation, ['pricing_mode' => 'percent', 'profit_percentage' => ''])->assertOk();

        $expected = ProductsVariation::computeSellingPrice(4, app(VariationPricing::class)->defaultProfit());
        $variation->refresh();
        $this->assertNull($variation->profit_percentage);
        $this->assertSame($expected, (float) $variation->price);
        $this->assertFalse($variation->manual_price);
    }

    public function test_fixed_mode_needs_a_price(): void
    {
        $variation = $this->variation(['price' => 10, 'cost_price' => 5]);

        $this->save($variation, ['pricing_mode' => 'fixed', 'price' => ''])
            ->assertStatus(302)
            ->assertSessionHasErrors('price');
    }

    public function test_creating_with_a_percent_derives_the_price_and_returns_to_the_product(): void
    {
        $sibling = $this->createVariation();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post($this->url(), $this->payload($sibling, [
                'slug' => 'made-' . uniqid(),
                'price' => '',
                'cost_price' => '4',
                'profit_percentage' => '25',
                'pricing_mode' => 'percent',
                'return_to' => 'product',
            ]))
            ->assertOk();

        $created = ProductsVariation::where('product_id', $sibling->product_id)->where('id', '!=', $sibling->id)->firstOrFail();

        $this->assertSame(5.0, (float) $created->price);
        $this->assertFalse((bool) $created->manual_price);
        $this->assertSame(
            url('/' . config('hellotree.cms_route_prefix') . '/products/' . $sibling->product_id . '/edit') . '#variations',
            $response->getContent()
        );
    }

    public function test_percent_mode_without_a_cost_is_refused(): void
    {
        $sibling = $this->createVariation();

        $this->actingAs($this->admin(), 'admin')
            ->post($this->url(), $this->payload($sibling, [
                'slug' => 'nocost-' . uniqid(),
                'price' => '',
                'cost_price' => '',
                'pricing_mode' => 'percent',
            ]))
            ->assertSessionHasErrors('cost_price');

        $this->assertSame(1, ProductsVariation::where('product_id', $sibling->product_id)->count());
    }

    /** Anything that does not send pricing_mode keeps the vendor's behaviour exactly. */
    public function test_a_save_without_a_pricing_mode_is_the_vendor_save(): void
    {
        $variation = $this->variation(['price' => 10, 'cost_price' => 5, 'profit_percentage' => null, 'manual_price' => 0]);

        $this->save($variation, ['price' => '13'])->assertOk();

        $variation->refresh();
        $this->assertSame(13.0, (float) $variation->price);
        $this->assertTrue($variation->manual_price);
    }

    public function test_the_form_renders_with_the_product_locked(): void
    {
        $sibling = $this->createVariation();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/create') . '?product_id=' . $sibling->product_id . '&return_to=product')
            ->assertOk()
            ->assertSee('How is it priced?')
            ->assertSee('name="product_id" value="' . $sibling->product_id . '"', false)
            ->assertSee('name="return_to" value="product"', false);
    }

    public function test_the_edit_form_renders(): void
    {
        $variation = $this->variation(['price' => 11, 'cost_price' => 10, 'profit_percentage' => 3, 'manual_price' => 1]);

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/' . $variation->id . '/edit'))
            ->assertOk()
            // A locked price reads as fixed, whatever % is stored beside it.
            ->assertSee('id="pe-mode-fixed" name="pricing_mode" value="fixed" class="custom-control-input" checked', false);
    }
}
