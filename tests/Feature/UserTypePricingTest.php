<?php

namespace Tests\Feature;

use App\Models\User;
use App\ProductPriceVariation;
use App\ProductsVariation;
use App\UserType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * Business-tier pricing.
 *
 * The storefront resolves the tier price on the client, matching the user's type against
 * the variation's `price_variations`. /user/profile is the ONLY place it learns which
 * type the user is on, so this pins that contract: dropping `user_types` from the payload
 * again silently sends every tier customer back to the default price while
 * OrderController keeps charging them the tier one.
 */
class UserTypePricingTest extends TestCase
{
    use CreatesCatalog;

    private function tierUser(?int $userTypeId): User
    {
        return User::create([
            'username' => 'tier_' . uniqid(),
            'email' => 'tier_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 100,
            'total_purchases' => 0,
            'received_amount' => 0,
            'user_types_id' => $userTypeId,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function userType(): UserType
    {
        $type = UserType::create(['slug' => 'tier-' . uniqid()]);

        DB::table('user_types_translations')->insert([
            'user_type_id' => $type->id,
            'locale' => 'en',
            'title' => 'Shop owner',
        ]);

        return $type->refresh();
    }

    public function test_profile_exposes_the_users_price_tier(): void
    {
        $type = $this->userType();
        $user = $this->tierUser($type->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/en/user/profile');

        $response->assertOk();
        // Both the flat column the storefront matches on and the relation the dashboard
        // badge and the account-settings dropdown read.
        $response->assertJsonPath('user.user_types_id', $type->id);
        $response->assertJsonPath('user.user_types.id', $type->id);
        $response->assertJsonPath('user.user_types.slug', $type->slug);
    }

    public function test_profile_does_not_ship_the_whole_price_table(): void
    {
        $type = $this->userType();
        $variation = $this->createVariation(10.00);

        ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'price' => 4.00,
        ]);

        Sanctum::actingAs($this->tierUser($type->id));

        $response = $this->getJson('/api/en/user/profile');

        $response->assertOk();
        // user_types.priceVariations is deliberately NOT loaded: that second level is what
        // dragged every product's price table into every authenticated request.
        $this->assertArrayNotHasKey('price_variations', $response->json('user.user_types'));
    }

    public function test_profile_of_a_plain_account_has_no_tier(): void
    {
        Sanctum::actingAs($this->tierUser(null));

        $response = $this->getJson('/api/en/user/profile');

        $response->assertOk();
        $response->assertJsonPath('user.user_types_id', null);
        $response->assertJsonPath('user.user_types', null);
    }

    public function test_variation_payload_carries_the_tier_prices_the_client_matches_on(): void
    {
        $type = $this->userType();
        $variation = $this->createVariation(10.00);

        ProductPriceVariation::create([
            'products_variations_id' => $variation->id,
            'user_types_id' => $type->id,
            'price' => 4.00,
        ]);

        $fresh = ProductsVariation::find($variation->id)->toArray();

        $this->assertArrayHasKey('price_variations', $fresh);
        $this->assertSame($type->id, (int) $fresh['price_variations'][0]['user_types_id']);
        $this->assertEquals(4.00, (float) $fresh['price_variations'][0]['price']);
    }
}
