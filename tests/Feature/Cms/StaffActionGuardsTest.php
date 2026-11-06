<?php

namespace Tests\Feature\Cms;

use App\ApiKey;
use App\ApiWebhook;
use App\CreditsTransfer;
use App\CreditsType;
use App\Models\User;
use App\Order;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regressions from security audit run-5: what staff could still do around the guards. */
class StaffActionGuardsTest extends TestCase
{
    use CreatesCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Guard Admin';
        $admin->email = 'guard_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin(), 'admin');
    }

    private function user(float $balance = 100): User
    {
        return User::create([
            'username' => 'guard_' . uniqid(),
            'email' => 'guard_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, int $status, array $extra = []): Order
    {
        return Order::create($extra + [
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation(10)->id,
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
        ]);
    }

    private function transfer(User $user, int $status): CreditsTransfer
    {
        return CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 10,
            'credits_types_id' => CreditsType::create(['slug' => 'guard-' . uniqid()])->id,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
            'receipt_image' => 'receipts/x.png',
        ]);
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    // --- Deleted records -------------------------------------------------------------

    public function test_purge_cannot_reach_a_live_order(): void
    {
        $order = $this->order($this->user(), Order::STATUS_APPROVED);

        $this->asAdmin()->delete($this->url("deleted-records/orders/{$order->id}"));

        $this->assertNotNull(Order::withTrashed()->find($order->id));
    }

    public function test_purge_cannot_reach_a_live_topup_or_user(): void
    {
        $user = $this->user();
        $transfer = $this->transfer($user, CreditsTransfer::STATUS_APPROVED);

        $this->asAdmin()->delete($this->url("deleted-records/credits-transfer/{$transfer->id}"));
        $this->asAdmin()->delete($this->url("deleted-records/users/{$user->id}"));

        $this->assertNotNull(CreditsTransfer::withTrashed()->find($transfer->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_purge_refuses_a_deleted_order_that_still_holds_credits(): void
    {
        $order = $this->order($this->user(), Order::STATUS_APPROVED);
        $order->delete();

        $this->asAdmin()->delete($this->url("deleted-records/orders/{$order->id}"));

        $this->assertNotNull(Order::withTrashed()->find($order->id));
    }

    public function test_purge_refuses_a_deleted_topup_that_credited_the_customer(): void
    {
        $transfer = $this->transfer($this->user(), CreditsTransfer::STATUS_APPROVED);
        $transfer->delete();

        $this->asAdmin()->delete($this->url("deleted-records/credits-transfer/{$transfer->id}"));

        $this->assertNotNull(CreditsTransfer::withTrashed()->find($transfer->id));
    }

    public function test_purge_still_removes_a_deleted_rejected_order(): void
    {
        $order = $this->order($this->user(), Order::STATUS_REJECTED);
        $order->delete();

        $this->asAdmin()->delete($this->url("deleted-records/orders/{$order->id}"));

        $this->assertNull(Order::withTrashed()->find($order->id));
    }

    public function test_restoring_a_user_revives_no_old_credential(): void
    {
        $user = $this->user();
        $user->createToken('old');
        $issued = ApiKey::issue($user, 'old-key');
        ApiWebhook::create(['user_id' => $user->id, 'url' => 'https://hook.example.test/x', 'secret' => 's']);
        $user->forceFill(['password_reset_token' => 'abc'])->save();
        $user->delete();

        $this->asAdmin()->put($this->url("deleted-records/users/{$user->id}/restore"));

        $fresh = User::find($user->id);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(0, ApiKey::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, ApiWebhook::where('user_id', $user->id)->count());
        $this->assertNull($fresh->password_reset_token);
        $this->assertNotNull($issued);
    }

    // --- Draft flag ------------------------------------------------------------------

    public function test_the_draft_flag_in_the_query_string_cannot_hide_a_paid_order(): void
    {
        $order = $this->order($this->user(), Order::STATUS_APPROVED);

        $this->asAdmin()->put($this->url("orders/{$order->id}?draft_cms_field=1"), ['statuses_id' => Order::STATUS_APPROVED]);

        $this->assertNotNull(Order::find($order->id));
        $this->assertSame(0, (int) DB::table('orders')->where('id', $order->id)->value('cms_draft_flag'));
    }

    public function test_the_draft_flag_in_the_query_string_cannot_blank_a_topup(): void
    {
        $transfer = $this->transfer($this->user(), CreditsTransfer::STATUS_APPROVED);

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}?draft_cms_field=1"), [
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'users_id' => $transfer->users_id,
            'amount' => 10,
            'credits_types_id' => $transfer->credits_types_id,
        ]);

        $this->assertSame(0, (int) DB::table('credits_transfer')->where('id', $transfer->id)->value('cms_draft_flag'));
        $this->assertEquals(10.0, (float) $transfer->fresh()->amount);
    }

    public function test_the_draft_flag_cannot_blank_a_user_row(): void
    {
        $user = $this->user();

        $this->asAdmin()->put($this->url("users/{$user->id}?draft_cms_field=1"), ['username' => $user->username, 'email' => $user->email]);

        $this->assertSame(0, (int) DB::table('users')->where('id', $user->id)->value('cms_draft_flag'));
        $this->assertSame(User::VERIFICATION_APPROVED, (int) DB::table('users')->where('id', $user->id)->value('verification_statuses_id'));
    }

    // --- Soft-deleted owners ---------------------------------------------------------

    public function test_an_order_of_a_deleted_customer_cannot_be_approved(): void
    {
        $user = $this->user();
        $order = $this->order($user, Order::STATUS_PENDING);
        $user->delete();

        $this->asAdmin()->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_APPROVED])
            ->assertSessionHasErrors('statuses_id');

        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->statuses_id);
        $this->assertEmpty(Queue::pushedJobs());
    }

    public function test_an_order_of_a_deleted_customer_can_still_be_rejected_and_refunded(): void
    {
        $user = $this->user(90);
        $order = $this->order($user, Order::STATUS_PENDING);
        $user->delete();

        $this->asAdmin()->put($this->url("orders/{$order->id}"), ['statuses_id' => Order::STATUS_REJECTED]);

        $this->assertSame(Order::STATUS_REJECTED, (int) $order->fresh()->statuses_id);
        $this->assertEquals(100.0, (float) User::withTrashed()->find($user->id)->credits_balance);
    }

    public function test_a_topup_of_a_deleted_customer_cannot_be_approved(): void
    {
        $user = $this->user();
        $transfer = $this->transfer($user, CreditsTransfer::STATUS_PENDING);
        $user->delete();

        $this->asAdmin()->put($this->url("credits-transfer/{$transfer->id}"), [
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'users_id' => $user->id,
            'amount' => 10,
            'credits_types_id' => $transfer->credits_types_id,
        ])->assertSessionHasErrors('statuses_id');

        $this->assertEquals(100.0, (float) User::withTrashed()->find($user->id)->credits_balance);
    }

    // --- KYC on the Users form -------------------------------------------------------

    public function test_the_users_form_cannot_change_a_kyc_decision(): void
    {
        $user = $this->user();
        $user->forceFill(['verification_statuses_id' => User::VERIFICATION_PENDING])->save();

        $this->asAdmin()->put($this->url("users/{$user->id}"), [
            'username' => $user->username,
            'email' => $user->email,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
            'rejection_reason' => 'stale',
        ]);

        $fresh = $user->fresh();
        $this->assertSame(User::VERIFICATION_PENDING, (int) $fresh->verification_statuses_id);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_the_kyc_fields_are_off_the_users_form_but_stay_registered(): void
    {
        $fields = collect(json_decode(DB::table('cms_pages')->where('route', 'users')->value('fields'), true))->keyBy('name');

        $this->assertSame(1, (int) $fields['verification_statuses_id']['hide_edit']);
        $this->assertSame(1, (int) $fields['rejection_reason']['hide_edit']);
    }

    // --- Admin session binding -------------------------------------------------------

    public function test_a_request_that_did_not_change_the_password_does_not_adopt_a_new_one(): void
    {
        $admin = $this->admin();
        $guard = Auth::guard('admin');

        // A request in flight while another session changes the password.
        Route::middleware('web')->post('/_test/in-flight', function () use ($admin) {
            Admin::where('id', $admin->id)->update(['password' => bcrypt('Changed-Passw0rd')]);

            return 'ok';
        });

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->withSession([
                $guard->getName() => $admin->id,
                'password_hash_admin' => $guard->hashPasswordForCookie($admin->password),
            ])->post('/_test/in-flight')->assertOk();

        // The guard caches its user across requests in a test, unlike a real request.
        $this->app['auth']->forgetGuards();

        $this->get('/admin/profile')->assertRedirect(route('admin-login'));
    }

    public function test_a_failing_password_request_does_not_adopt_a_change_made_elsewhere(): void
    {
        $admin = $this->admin();
        $guard = Auth::guard('admin');
        $guard->login($admin);
        $store = app('session.store');
        $before = $store->get('password_hash_admin');

        $prefix = trim((string) config('hellotree.cms_route_prefix'), '/');
        $request = \Illuminate\Http\Request::create("/{$prefix}/profile/edit", 'POST', ['password' => 'Guessed-Passw0rd', 'password_confirmation' => 'other']);
        $request->setLaravelSession($store);

        (new \App\Http\Middleware\BindAdminSessionToPassword())->handle($request, function () use ($admin) {
            // The real owner changes it while this request, which fails validation, is running.
            Admin::where('id', $admin->id)->update(['password' => bcrypt('Owners-New-Passw0rd')]);

            return response('refused', 422);
        });

        $this->assertSame($before, $store->get('password_hash_admin'));
    }
}
