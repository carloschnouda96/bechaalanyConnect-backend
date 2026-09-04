<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use App\Order;
use App\UserNotification;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * A CMS-registered column that the model casts to `array` cannot be rendered by the
 * vendor's generic templates, which stringify every attribute they do not have a
 * dedicated branch for:
 *
 *   pages/cms-page/index.blade.php:171   {{ $row[$field['name']] }}      → htmlspecialchars()
 *   components/show-fields/text.blade.php  {{ strip_tags($text) }}       → strip_tags()
 *
 * Both are `string` parameters, so an array is a PHP 8 TypeError and the whole page
 * 500s — "htmlspecialchars(): Argument #1 ($string) must be of type string, array
 * given" on Notifications, which is the only one of the three json columns still shown
 * on a list. `orders.external_response` and `products_variations.external_qty_values`
 * are cast the same way and are hidden from their lists but NOT from their show pages,
 * so View on either was the same crash waiting to be clicked.
 *
 * The payload is the only place a notification's message lives, so hiding the column is
 * not an option — it has to render.
 */
class JsonColumnRenderTest extends TestCase
{
    use CreatesCatalog;

    private function superAdmin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Super';
        $admin->email = 'json_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(): User
    {
        return User::create([
            'username' => 'json_' . uniqid(),
            'email' => 'json_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    /** A correctly-stored row: data written as an array, so it reads back as one. */
    private function notification(): UserNotification
    {
        return UserNotification::create([
            'users_id' => $this->user()->id,
            'type' => 'general',
            'data' => [
                'message' => 'Your credit request of 50 has been approved.',
                'amount' => 50,
                'new_status' => '1',
            ],
        ]);
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    public function test_the_notifications_list_renders_an_array_payload(): void
    {
        $notification = $this->notification();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('user-notifications'))
            ->assertOk()
            ->assertSee('Your credit request of 50 has been approved.', false);

        $this->assertIsArray($notification->fresh()->data, 'the array cast must survive the fix');
    }

    public function test_the_notification_show_page_renders_an_array_payload(): void
    {
        $notification = $this->notification();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('user-notifications/' . $notification->id))
            ->assertOk()
            ->assertSee('Your credit request of 50 has been approved.', false);
    }

    /**
     * The payload is written by the application, and a textarea round trip through the
     * `array` cast would json_encode the edited string a second time. Both forms have to
     * leave it out.
     */
    public function test_the_notification_form_does_not_offer_the_payload_for_editing(): void
    {
        $notification = $this->notification();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->get($this->url('user-notifications/' . $notification->id . '/edit'))
            ->assertOk()
            ->assertDontSee('name="data"', false);

        $this->actingAs($admin, 'admin')
            ->get($this->url('user-notifications/create'))
            ->assertOk()
            ->assertDontSee('name="data"', false);
    }

    /**
     * The other two array-cast json columns. Both are hidden from their lists but shown
     * on their show pages, which routes them to components/show-fields/text — the same
     * crash, one click further in.
     */
    public function test_the_order_show_page_renders_an_array_supplier_response(): void
    {
        $order = Order::create([
            'users_id' => $this->user()->id,
            'product_variation_id' => $this->createVariation(5.00)->id,
            'quantity' => 1,
            'total_price' => 5.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_source' => 'yassen',
            'external_response' => ['status' => 'accept', 'replay_api' => 'CODE-123'],
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('orders/' . $order->id))
            ->assertOk()
            ->assertSee('replay_api', false);
    }

    public function test_the_variation_show_page_renders_array_qty_values(): void
    {
        $variation = $this->createVariation(5.00);
        $variation->external_qty_values = ['min' => 1, 'max' => 10];
        $variation->save();

        // The show page reads every translatable field off $row->translate($locale), and
        // dereferences it without a null check — a variation with no translation row 500s
        // there before it ever reaches the field under test.
        foreach (DB::table('languages')->pluck('slug') as $locale) {
            DB::table('products_variations_translations')->insert([
                'locale' => $locale,
                'products_variation_id' => $variation->id,
                'name' => 'Variation ' . $locale,
                'description' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->superAdmin(), 'admin')
            ->get($this->url('products-variations/' . $variation->id))
            ->assertOk()
            ->assertSee('max', false);
    }
}
