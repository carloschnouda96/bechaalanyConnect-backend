<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Order;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\UserType;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Per-user-type pricing as a fixed price OR a profit % on cost.
 *
 * The contract: product_price_variations.price always holds the RESOLVED price, because
 * that is the one column OrderController::saveOrder charges and the storefront shows.
 * profit_percentage is the rule stored beside it; the observers keep the two in step.
 */
class PriceMatrixTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Matrix Admin';
        $admin->email = 'matrix_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function userType(): UserType
    {
        $type = UserType::create(['slug' => 'tier-' . uniqid()]);

        DB::table('user_types_translations')->insert([
            'user_type_id' => $type->id,
            'locale' => 'en',
            'title' => 'Reseller ' . $type->id,
        ]);

        return $type->refresh();
    }

    /** Variation priced 10.00 publicly with a cost of 5.00. */
    private function variation(?float $cost = 5.00): ProductsVariation
    {
        $variation = $this->createVariation(10.00);
        DB::table('products_variations')->where('id', $variation->id)->update(['cost_price' => $cost]);

        return $variation->fresh();
    }

    private function url(): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/price-matrix';
    }

    private function putChanges(array $changes)
    {
        return $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), ['changes' => json_encode($changes)]);
    }

    private function tierRow(ProductsVariation $variation, UserType $type): ?ProductPriceVariation
    {
        return ProductPriceVariation::where('products_variations_id', $variation->id)
            ->where('user_types_id', $type->id)
            ->first();
    }

    public function test_a_percent_tier_row_stores_the_resolved_price(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        $row = ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'profit_percentage' => 10,
        ]);

        $this->assertEquals(ProductsVariation::computeSellingPrice(5.00, 10.0), (float) $row->fresh()->price);
        $this->assertEquals(5.50, (float) $row->fresh()->price);
    }

    public function test_a_percent_tier_row_without_a_cost_is_refused_rather_than_sold_free(): void
    {
        $type = $this->userType();
        $variation = $this->variation(null);

        $this->expectException(ValidationException::class);

        ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'profit_percentage' => 10,
        ]);
    }

    /** What the supplier sync does to a variation every hour. */
    public function test_a_cost_change_reprices_percent_tiers_and_leaves_fixed_ones(): void
    {
        $percentType = $this->userType();
        $fixedType = $this->userType();
        $variation = $this->variation(5.00);

        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $percentType->id, 'profit_percentage' => 10]);
        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $fixedType->id, 'price' => 7.00]);

        ProductsVariation::applyingSystemPricing(function () use ($variation) {
            $variation->cost_price = 8.00;
            $variation->save();
        });

        $this->assertEquals(8.80, (float) $this->tierRow($variation, $percentType)->price);
        $this->assertEquals(7.00, (float) $this->tierRow($variation, $fixedType)->price);
    }

    public function test_an_order_is_charged_the_percent_derived_tier_price(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $type->id, 'profit_percentage' => 20]);

        $user = User::create([
            'username' => 'buyer_' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'user_types_id' => $type->id,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
        DB::table('users')->where('id', $user->id)->update(['credits_balance' => 100]);

        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/en/save-order', [
            'product_variation_id' => $variation->id,
            'quantity' => 2,
        ])->assertSuccessful();

        $order = Order::where('users_id', $user->id)->latest('id')->first();
        $this->assertEquals(12.00, (float) $order->total_price, '2 x (5.00 x 1.20)');
        $this->assertEquals(88.00, (float) DB::table('users')->where('id', $user->id)->value('credits_balance'));
    }

    public function test_the_tier_profit_percentage_never_reaches_the_public_payload(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $type->id, 'profit_percentage' => 10]);

        $payload = ProductsVariation::find($variation->id)->toArray();

        $this->assertEquals(5.50, (float) $payload['price_variations'][0]['price']);
        $this->assertArrayNotHasKey('profit_percentage', $payload['price_variations'][0]);
    }

    public function test_the_same_variation_and_user_type_cannot_hold_two_prices(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $type->id, 'price' => 6]);

        $this->expectException(QueryException::class);
        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $type->id, 'price' => 7]);
    }

    public function test_the_matrix_sets_fixed_percent_and_clear_on_a_tier_cell(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);
        $cell = ['variation_id' => $variation->id, 'column' => (string) $type->id];

        $this->putChanges([$cell + ['mode' => 'fixed', 'value' => 6.25]])->assertRedirect()->assertSessionHasNoErrors();
        $row = $this->tierRow($variation, $type);
        $this->assertEquals(6.25, (float) $row->price);
        $this->assertNull($row->profit_percentage);

        $this->putChanges([$cell + ['mode' => 'percent', 'value' => 30]])->assertSessionHasNoErrors();
        $row = $this->tierRow($variation, $type);
        $this->assertEquals(6.50, (float) $row->price);
        $this->assertEquals(30.00, (float) $row->profit_percentage);

        $this->putChanges([$cell + ['mode' => 'clear', 'value' => null]])->assertSessionHasNoErrors();
        $this->assertNull($this->tierRow($variation, $type), 'cleared → the tier pays the public price');
    }

    public function test_the_matrix_skips_a_percent_on_a_variation_without_cost(): void
    {
        $type = $this->userType();
        $variation = $this->variation(null);

        $this->putChanges([['variation_id' => $variation->id, 'column' => (string) $type->id, 'mode' => 'percent', 'value' => 10]])
            ->assertRedirect()
            ->assertSessionHas('price_matrix_skipped');

        $this->assertNull($this->tierRow($variation, $type));
    }

    public function test_the_matrix_public_column_keeps_the_existing_variation_semantics(): void
    {
        $variation = $this->variation(5.00);
        $cell = ['variation_id' => $variation->id, 'column' => 'public'];

        $this->putChanges([$cell + ['mode' => 'percent', 'value' => 50]])->assertSessionHasNoErrors();
        $this->assertEquals(7.50, (float) $variation->fresh()->price);
        $this->assertFalse((bool) $variation->fresh()->manual_price);

        $this->putChanges([$cell + ['mode' => 'fixed', 'value' => 9.99]])->assertSessionHasNoErrors();
        $this->assertEquals(9.99, (float) $variation->fresh()->price);
        $this->assertTrue((bool) $variation->fresh()->manual_price, 'fixed locks the price against the sync');

        // Same % as before on a locked row still hands the price back to the formula.
        $this->putChanges([$cell + ['mode' => 'percent', 'value' => 50]])->assertSessionHasNoErrors();
        $this->assertEquals(7.50, (float) $variation->fresh()->price);
        $this->assertFalse((bool) $variation->fresh()->manual_price);

        $this->putChanges([$cell + ['mode' => 'clear', 'value' => null]])->assertSessionHasNoErrors();
        $this->assertNull($variation->fresh()->profit_percentage, 'cleared → inherits the Fixed Settings default');
    }

    public function test_a_bulk_action_applies_one_value_to_every_selected_variation(): void
    {
        $type = $this->userType();
        $a = $this->variation(5.00);
        $b = $this->variation(2.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), [
                'bulk_ids' => $a->id . ',' . $b->id,
                'bulk_column' => (string) $type->id,
                'bulk_mode' => 'percent',
                'bulk_value' => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(5.50, (float) $this->tierRow($a, $type)->price);
        $this->assertEquals(2.20, (float) $this->tierRow($b, $type)->price);
    }

    public function test_an_out_of_range_percent_is_rejected(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        $this->putChanges([['variation_id' => $variation->id, 'column' => (string) $type->id, 'mode' => 'percent', 'value' => -150]])
            ->assertSessionHasErrors();

        $this->assertNull($this->tierRow($variation, $type));
    }

    public function test_the_matrix_lists_variations_with_their_tier_cells(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        ProductPriceVariation::create(['products_variations_id' => $variation->id, 'user_types_id' => $type->id, 'profit_percentage' => 10]);

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url() . '?q=' . $variation->product->slug)
            ->assertOk()
            ->assertSee('Reseller ' . $type->id)
            ->assertSee('data-orig-mode="percent"', false)
            ->assertSee('data-orig-value="10"', false);
    }

    /**
     * A hand-priced manual variation (manual_price = 0, price unrelated to cost) must not
     * be labelled "default %" — that would claim a formula the price never went through.
     */
    public function test_a_price_that_is_not_cost_times_percent_shows_as_fixed(): void
    {
        $variation = $this->variation(1.00); // price 10.00, nowhere near 1.00 x (1 + default%)

        $html = $this->actingAs($this->admin(), 'admin')
            ->get($this->url() . '?q=' . $variation->product->slug)
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-column="public"\s+data-orig-mode="fixed"/', $html);
    }

    /** Ported from the retired Bulk pricing page: "adjust prices by %". */
    public function test_adjusting_the_public_price_by_percent_moves_a_manual_variation(): void
    {
        $variation = $this->variation(4.00); // manual product, price 10.00

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), [
                'bulk_ids' => (string) $variation->id,
                'bulk_column' => 'public',
                'bulk_mode' => 'adjust',
                'bulk_value' => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(11.00, (float) $variation->fresh()->price);
        $this->assertTrue((bool) $variation->fresh()->manual_price, 'an adjusted price is a hand-set price');
    }

    /** A supplier price is derived and the next sync would revert a direct write. */
    public function test_adjusting_refuses_a_supplier_variation(): void
    {
        $variation = $this->variation(5.00);
        DB::table('products')->where('id', $variation->product_id)->update(['external_source' => 'swift', 'external_id' => 'ext-' . uniqid()]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), [
                'bulk_ids' => (string) $variation->id,
                'bulk_column' => 'public',
                'bulk_mode' => 'adjust',
                'bulk_value' => 10,
            ])
            ->assertSessionHas('price_matrix_skipped');

        $this->assertEquals(10.00, (float) $variation->fresh()->price, 'supplier price must be untouched');
    }

    public function test_adjusting_is_refused_on_a_user_type_column(): void
    {
        $type = $this->userType();
        $variation = $this->variation(5.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), [
                'bulk_ids' => (string) $variation->id,
                'bulk_column' => (string) $type->id,
                'bulk_mode' => 'adjust',
                'bulk_value' => 10,
            ])
            ->assertSessionHasErrors();

        $this->assertNull($this->tierRow($variation, $type));
    }

    /** Ported from Bulk pricing: setting a profit % reprices a supplier variation from cost. */
    public function test_a_public_profit_percent_reprices_a_supplier_variation_from_cost(): void
    {
        $variation = $this->variation(5.00);
        DB::table('products')->where('id', $variation->product_id)->update(['external_source' => 'yassen', 'external_id' => 'ext-' . uniqid()]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url(), [
                'bulk_ids' => (string) $variation->id,
                'bulk_column' => 'public',
                'bulk_mode' => 'percent',
                'bulk_value' => 20,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(ProductsVariation::computeSellingPrice(5.00, 20.0), (float) $variation->fresh()->price);
        $this->assertEquals(6.00, (float) $variation->fresh()->price);
    }

    public function test_the_page_requires_an_admin(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
