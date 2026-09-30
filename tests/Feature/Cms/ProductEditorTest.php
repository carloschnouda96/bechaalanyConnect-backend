<?php

namespace Tests\Feature\Cms;

use App\Product;
use App\ProductsVariation;
use App\UserType;
use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The product page as the one place a product is set up: the Variations card, its
 * quick-add row, per-product price saving, and create landing on the new product.
 */
class ProductEditorTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Editor';
        $admin->email = 'editor_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    /** A role admin granted only the Products page — no Prices, no Variations. */
    private function productsOnlyAdmin(): Admin
    {
        $role = new AdminRole();
        $role->title = 'Catalog ' . uniqid();
        $role->save();

        $permission = new AdminRolePermission();
        $permission->admin_role_id = $role->id;
        $permission->cms_page_id = CmsPage::where('route', 'products')->value('id');
        $permission->browse = 1;
        $permission->read = 1;
        $permission->edit = 1;
        $permission->add = 1;
        $permission->delete = 0;
        $permission->save();

        $admin = new Admin();
        $admin->name = 'Staff';
        $admin->email = 'staff_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = $role->id;
        $admin->save();

        return $admin->refresh();
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . $path;
    }

    private function emptyProduct(?string $source = null): Product
    {
        $product = $this->createVariation()->product;
        ProductsVariation::where('product_id', $product->id)->delete();

        if ($source) {
            $product->update(['external_source' => $source, 'external_id' => 'ext-' . uniqid()]);
        }

        return $product->fresh();
    }

    public function test_the_edit_page_lists_variations_with_their_price_cells(): void
    {
        $variation = $this->createVariation(10.00);
        DB::table('products_variations_translations')->insert([
            'products_variation_id' => $variation->id, 'locale' => 'en', 'name' => 'Fifty Gems',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/products/' . $variation->product_id . '/edit'))
            ->assertOk()
            ->assertSee('Variations &amp; prices', false)
            ->assertSee('Fifty Gems')
            ->assertSee('Save prices')
            ->assertSee('Quick add a variation')
            ->assertDontSee('Not for sale.');
    }

    public function test_a_product_without_variations_is_flagged_not_for_sale(): void
    {
        $product = $this->emptyProduct();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/products/' . $product->id . '/edit'))
            ->assertOk()
            ->assertSee('Not for sale.')
            ->assertSee('has no variations yet');
    }

    public function test_quick_add_creates_a_fixed_price_variation(): void
    {
        $product = $this->emptyProduct();
        $before = (int) ProductsVariation::withoutGlobalScope('cms_draft_flag')->max('ht_pos');

        $this->actingAs($this->admin(), 'admin')
            ->post($this->url('/products/' . $product->id . '/variations'), [
                'name' => ['en' => '100 UC', 'ar' => ''],
                'cost_price' => '1.5',
                'pricing_mode' => 'fixed',
                'pricing_value' => '2.25',
            ])
            ->assertRedirect($this->url('/products/' . $product->id . '/edit') . '#variations')
            ->assertSessionHasNoErrors();

        $variation = ProductsVariation::where('product_id', $product->id)->firstOrFail();

        $this->assertSame(2.25, (float) $variation->price);
        $this->assertSame(1.5, (float) $variation->cost_price);
        $this->assertTrue($variation->manual_price);
        $this->assertSame(1, (int) $variation->is_active);
        $this->assertSame($before + 1, (int) $variation->ht_pos);
        $this->assertSame('100 UC', $variation->translate('en')->name);
        // No Arabic name given: the English one stands in rather than an empty label.
        $this->assertSame('100 UC', $variation->translate('ar')->name);
        $this->assertStringStartsWith($product->slug . '-100-uc', $variation->slug);
    }

    public function test_quick_add_with_a_profit_percent_derives_the_price_from_cost(): void
    {
        $product = $this->emptyProduct();

        $this->actingAs($this->admin(), 'admin')
            ->post($this->url('/products/' . $product->id . '/variations'), [
                'name' => ['en' => 'Big', 'ar' => 'كبير'],
                'cost_price' => '10',
                'pricing_mode' => 'percent',
                'pricing_value' => '50',
            ])
            ->assertSessionHasNoErrors();

        $variation = ProductsVariation::where('product_id', $product->id)->firstOrFail();

        $this->assertSame(15.0, (float) $variation->price);
        $this->assertSame(50.0, (float) $variation->profit_percentage);
        $this->assertFalse($variation->manual_price);
        $this->assertSame('كبير', $variation->translate('ar')->name);
    }

    public function test_quick_add_requires_a_name_and_a_cost(): void
    {
        $product = $this->emptyProduct();

        $this->actingAs($this->admin(), 'admin')
            ->post($this->url('/products/' . $product->id . '/variations'), [
                'name' => ['en' => ''],
                'pricing_mode' => 'fixed',
                'pricing_value' => '5',
            ])
            ->assertSessionHasErrors(['name.en', 'cost_price']);

        $this->assertSame(0, ProductsVariation::where('product_id', $product->id)->count());
    }

    public function test_quick_add_is_refused_on_a_supplier_product(): void
    {
        $product = $this->emptyProduct('yassen');

        $this->actingAs($this->admin(), 'admin')
            ->post($this->url('/products/' . $product->id . '/variations'), [
                'name' => ['en' => 'Sneaky'],
                'cost_price' => '1',
                'pricing_mode' => 'fixed',
                'pricing_value' => '5',
            ])
            ->assertSessionHasErrors('quick_add');

        $this->assertSame(0, ProductsVariation::where('product_id', $product->id)->count());
    }

    public function test_prices_save_back_to_the_product_and_only_touch_its_variations(): void
    {
        $mine = $this->createVariation(10.00);
        $other = $this->createVariation(20.00);
        $type = UserType::create(['slug' => 'tier-' . uniqid()]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/products/' . $mine->product_id . '/prices'), [
                'changes' => json_encode([
                    ['variation_id' => $mine->id, 'column' => 'public', 'mode' => 'fixed', 'value' => '12.5'],
                    ['variation_id' => $mine->id, 'column' => (string) $type->id, 'mode' => 'fixed', 'value' => '11'],
                    // Not this product's: must be ignored, whatever the form carries.
                    ['variation_id' => $other->id, 'column' => 'public', 'mode' => 'fixed', 'value' => '1'],
                ]),
            ])
            ->assertRedirect($this->url('/products/' . $mine->product_id . '/edit') . '#variations')
            ->assertSessionHas('success', '2 price(s) updated.');

        $this->assertSame(12.5, (float) $mine->fresh()->price);
        $this->assertSame(11.0, (float) $mine->fresh()->priceVariations()->where('user_types_id', $type->id)->value('price'));
        $this->assertSame(20.0, (float) $other->fresh()->price);
    }

    public function test_a_products_only_admin_can_price_and_add_from_the_product_page(): void
    {
        $variation = $this->createVariation(10.00);
        $admin = $this->productsOnlyAdmin();

        $this->actingAs($admin, 'admin')
            ->get($this->url('/products/' . $variation->product_id . '/edit'))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->put($this->url('/products/' . $variation->product_id . '/prices'), [
                'changes' => json_encode([['variation_id' => $variation->id, 'column' => 'public', 'mode' => 'fixed', 'value' => '9']]),
            ])
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->post($this->url('/products/' . $variation->product_id . '/variations'), [
                'name' => ['en' => 'Extra'],
                'cost_price' => '1',
                'pricing_mode' => 'fixed',
                'pricing_value' => '3',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(9.0, (float) $variation->fresh()->price);
        $this->assertSame(2, ProductsVariation::where('product_id', $variation->product_id)->count());
    }

    public function test_creating_a_product_lands_on_its_edit_page(): void
    {
        Storage::fake('public');
        Storage::fake(config('filesystems.default'));
        $template = $this->createVariation()->product;
        $slug = 'fresh-' . uniqid();

        $response = $this->actingAs($this->admin(), 'admin')
            ->post($this->url('/products'), [
                'slug' => $slug,
                'image' => UploadedFile::fake()->image('p.png'),
                'subcategory_id' => $template->subcategory_id,
                'product_type_id' => 2,
                'is_active' => 'on',
                'en' => ['name' => 'Fresh product', 'description' => ''],
                'ar' => ['name' => 'منتج', 'description' => ''],
                'draft_cms_field' => 0,
            ])
            ->assertOk();

        $created = Product::withoutGlobalScope('cms_draft_flag')->where('slug', $slug)->firstOrFail();

        $this->assertSame(url($this->url('/products/' . $created->id . '/edit')) . '#variations', $response->getContent());
    }

    public function test_the_edit_page_requires_an_admin(): void
    {
        $product = $this->emptyProduct();

        $this->get($this->url('/products/' . $product->id . '/edit'))->assertRedirect();
    }
}
