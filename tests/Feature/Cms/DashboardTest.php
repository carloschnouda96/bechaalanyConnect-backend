<?php

namespace Tests\Feature\Cms;

use App\CreditsTransfer;
use App\Models\User;
use App\Order;
use App\Services\Cms\DashboardMetrics;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * The CMS home page was blank. These tests cover the replacement: that it renders
 * for a signed-in admin, and that the counts it shows are the real ones.
 */
class DashboardTest extends TestCase
{
    use CreatesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        // Metrics are cached ~60s; tests must see their own writes.
        Cache::flush();
    }

    /**
     * Built attribute-by-attribute: the vendor's Admin model declares neither
     * $fillable nor $guarded, so Eloquent refuses mass assignment entirely.
     */
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Test Admin';
        $admin->email = 'admin_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        // null = super admin, which skips the per-page permission check in
        // AdminMiddleware and keeps this test about the dashboard.
        $admin->admin_role_id = null;
        $admin->save();

        // Re-read so every column is present. AdminMiddleware puts $admin->toArray()
        // on the request and the vendor layout indexes it directly (e.g. ['image']),
        // so a freshly constructed model that never touched those attributes would
        // blow up in the layout rather than in anything under test.
        return $admin->refresh();
    }

    private function user(float $balance = 0.0): User
    {
        return User::create([
            'username' => 'dash_' . uniqid(),
            'email' => 'dash_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    public function test_dashboard_renders_for_a_signed_in_admin(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get('/' . config('hellotree.cms_route_prefix') . '/home');

        $response->assertOk();
        $response->assertSee('Needs attention', false);
        $response->assertSee('Pending orders', false);
        $response->assertSee('System health', false);
    }

    public function test_dashboard_is_not_reachable_when_signed_out(): void
    {
        $this->get('/' . config('hellotree.cms_route_prefix') . '/home')
            ->assertRedirect();
    }

    public function test_queue_counts_reflect_real_pending_work(): void
    {
        $user = $this->user();
        $variation = $this->createVariation();

        Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 10.00,
            'statuses_id' => Order::STATUS_PENDING,
            'credits_applied_status' => Order::STATUS_PENDING,
        ]);

        CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 20.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);

        $pendingKyc = $this->user();
        $pendingKyc->update(['verification_statuses_id' => User::VERIFICATION_PENDING]);

        $queues = app(DashboardMetrics::class)->queues();

        $this->assertSame(1, $queues['pending_orders']);
        $this->assertSame(1, $queues['pending_topups']);
        $this->assertSame(1, $queues['pending_kyc']);
    }

    /**
     * Revenue is only trustworthy because orders.total_price is DECIMAL now — as
     * LONGTEXT, MySQL coerced non-numeric rows to 0 inside SUM() and under-reported.
     */
    public function test_revenue_sums_approved_orders_only(): void
    {
        $user = $this->user();
        $variation = $this->createVariation(price: 30.00); // cost_price 15.00

        foreach ([Order::STATUS_APPROVED, Order::STATUS_APPROVED, Order::STATUS_PENDING] as $status) {
            Order::create([
                'users_id' => $user->id,
                'product_variation_id' => $variation->id,
                'quantity' => 1,
                'total_price' => 30.00,
                'statuses_id' => $status,
                'credits_applied_status' => $status,
            ]);
        }

        $revenue = app(DashboardMetrics::class)->revenue();

        $this->assertSame(2, $revenue['today']['orders'], 'pending orders must not count as revenue');
        $this->assertEqualsWithDelta(60.00, $revenue['today']['revenue'], 0.01);
        $this->assertEqualsWithDelta(30.00, $revenue['today']['profit'], 0.01, 'profit = revenue - cost_price * qty');
    }

    /**
     * The condition that is otherwise completely invisible: an admin approved the
     * order, the customer was charged, and the supplier never received it.
     */
    public function test_health_surfaces_orders_that_were_never_sent_to_the_supplier(): void
    {
        $user = $this->user();
        $variation = $this->createVariation();

        Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 10.00,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_source' => 'umanage',
            'external_order_id' => null, // never placed
        ]);

        $this->assertGreaterThanOrEqual(1, app(DashboardMetrics::class)->health()['unplaced_supplier_orders']);
    }
}
