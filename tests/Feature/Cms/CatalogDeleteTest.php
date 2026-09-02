<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use Hellotreedigital\Cms\Models\Admin;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The guard on the Products / Products Variations delete button.
 *
 * `orders.product_variation_id` is ON DELETE RESTRICT, and an order deleted in the CMS
 * is only soft-deleted — invisible on every page but still holding that key. The delete
 * therefore failed with a raw SQLSTATE 1451 naming a row the admin could not find.
 */
class CatalogDeleteTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Ops';
        $admin->email = 'cat_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function orderFor(ProductsVariation $variation): Order
    {
        $user = User::create([
            'username' => 'cd_' . uniqid(),
            'email' => 'cd_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 5.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }

    private function variationUrl($id): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/products-variations/' . $id;
    }

    private function productUrl($id): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/products/' . $id;
    }

    public function test_a_variation_with_no_orders_is_deleted(): void
    {
        $variation = $this->createVariation();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($variation->id))
            ->assertRedirect();

        $this->assertNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variation->id));
    }

    public function test_a_variation_with_a_live_order_is_refused(): void
    {
        $variation = $this->createVariation();
        $this->orderFor($variation);

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($variation->id))
            ->assertRedirect();

        $this->assertNotNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variation->id));
        $this->assertStringContainsString('1 order(s) against it', session('success'));
        $this->assertStringContainsString('is active', session('success'));
    }

    public function test_a_variation_blocked_only_by_a_deleted_order_points_at_the_trash_page(): void
    {
        $variation = $this->createVariation();
        $this->orderFor($variation)->delete();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($variation->id))
            ->assertRedirect();

        $this->assertNotNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variation->id));
        $this->assertStringContainsString('1 deleted order(s)', session('success'));
        $this->assertStringContainsString('Deleted records', session('success'));
    }

    public function test_the_variation_is_deletable_once_the_blocking_order_is_purged(): void
    {
        $variation = $this->createVariation();
        $order = $this->orderFor($variation);
        $order->delete();
        $order->forceDelete();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($variation->id))
            ->assertRedirect();

        $this->assertNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variation->id));
    }

    public function test_a_bulk_delete_is_refused_as_a_whole_when_any_id_is_blocked(): void
    {
        $free = $this->createVariation();
        $blocked = $this->createVariation();
        $this->orderFor($blocked);

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($free->id . ',' . $blocked->id))
            ->assertRedirect();

        // Nothing is destroyed while part of the selection cannot be.
        $this->assertNotNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($free->id));
        $this->assertNotNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($blocked->id));
    }

    public function test_deleting_a_product_is_refused_when_a_variation_of_it_has_orders(): void
    {
        $variation = $this->createVariation();
        $this->orderFor($variation);

        // products_variations.product_id cascades, so the product delete hits the same
        // orders constraint one level down.
        $this->actingAs($this->admin(), 'admin')
            ->delete($this->productUrl($variation->product_id))
            ->assertRedirect();

        $this->assertNotNull(Product::withoutGlobalScope('cms_draft_flag')->find($variation->product_id));
        $this->assertStringContainsString('1 order(s) against it', session('success'));
    }

    public function test_a_supplier_imported_product_says_the_sync_will_recreate_it(): void
    {
        $variation = $this->createVariation();
        $this->orderFor($variation);

        Product::withoutGlobalScope('cms_draft_flag')
            ->where('id', $variation->product_id)
            ->update(['external_source' => 'yassen', 'external_id' => 'y-1']);

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->variationUrl($variation->id))
            ->assertRedirect();

        $this->assertStringContainsString('imported from yassen', session('success'));
        $this->assertStringContainsString('import excluded', session('success'));
    }

    public function test_a_product_with_no_orders_is_deleted(): void
    {
        $variation = $this->createVariation();

        $this->actingAs($this->admin(), 'admin')
            ->delete($this->productUrl($variation->product_id))
            ->assertRedirect();

        $this->assertNull(Product::withoutGlobalScope('cms_draft_flag')->find($variation->product_id));
        $this->assertNull(ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($variation->id));
    }
}
