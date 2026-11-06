<?php

namespace Tests\Feature;

use App\CreditsTransfer;
use App\CreditsType;
use App\Models\User;
use App\Order;
use App\ProductsVariation;
use App\Services\Reseller\WebhookUrlGuard;
use App\Services\Reseller\WebhookUrlRejected;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Yassen\YassenClient;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regressions from security audit run-5: bounds and edges found around the earlier fixes. */
class PlatformEdgeCasesTest extends TestCase
{
    use CreatesCatalog;

    private function user(): User
    {
        return User::create([
            'username' => 'edge_' . uniqid(),
            'email' => 'edge_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function supplierOrder(string $source, array $extra = []): Order
    {
        $variation = $this->createVariation(10);
        $variation->forceFill(['external_id' => '1', 'external_source' => $source])->save();

        return Order::create($extra + [
            'users_id' => $this->user()->id,
            'product_variation_id' => $variation->id,
            'recipient_user' => 'https://example.test/me',
            'quantity' => 1,
            'total_price' => 10,
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
            'external_source' => $source,
        ]);
    }

    // --- Webhook hosts ---------------------------------------------------------------

    public static function badHosts(): array
    {
        return [
            'leading hyphen' => ['https://-evil.example.test/hook'],
            'label with a leading hyphen' => ['https://a.-evil.example.test/hook'],
            'trailing hyphen' => ['https://evil-.example.test/hook'],
            'underscore' => ['https://ev_il.example.test/hook'],
        ];
    }

    #[DataProvider('badHosts')]
    public function test_a_webhook_host_curl_would_not_pin_is_refused(string $url): void
    {
        $guard = new WebhookUrlGuard(fn () => ['93.184.216.34']);

        $this->expectException(WebhookUrlRejected::class);
        $guard->resolve($url);
    }

    public function test_other_non_public_ranges_are_refused(): void
    {
        foreach (['fec0::1', '192.88.99.1', '168.63.129.16'] as $ip) {
            $this->assertFalse(WebhookUrlGuard::isPublicIp($ip), $ip);
        }
    }

    public function test_a_normal_webhook_host_is_accepted(): void
    {
        $guard = new WebhookUrlGuard(fn () => ['93.184.216.34']);

        $this->assertSame('hooks.example-store.test', $guard->resolve('https://hooks.example-store.test/x')['host']);
    }

    // --- Polling ---------------------------------------------------------------------

    public function test_new_pending_orders_do_not_outrank_ones_waiting_longer(): void
    {
        config([
            'services.1xpanel.base_url' => 'https://1xpanel.test', 'services.1xpanel.key' => 'k', 'services.1xpanel.enabled' => true,
        ]);
        $checked = [];
        Http::fake(function ($request) use (&$checked) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $checked[] = (string) ($query['order'] ?? '');

            return Http::response(['status' => 'In progress'], 200);
        });

        $base = ['external_status' => SupplierOrderResult::PENDING, 'external_order_uuid' => (string) Str::uuid()];
        $old = $this->supplierOrder('1xpanel', $base + ['external_order_id' => 'OLD']);
        Order::whereKey($old->id)->update(['last_polled_at' => now()->subHour(), 'created_at' => now()->subDay()]);
        $new = $this->supplierOrder('1xpanel', ['external_status' => SupplierOrderResult::PENDING, 'external_order_uuid' => (string) Str::uuid(), 'external_order_id' => 'NEW']);
        Order::whereKey($new->id)->update(['last_polled_at' => null, 'created_at' => now()]);

        Artisan::call('1xpanel:check-orders', ['--limit' => 1]);

        $this->assertSame(['OLD'], $checked);
    }

    public function test_an_order_parked_with_no_outcome_is_reported_once_it_has_aged(): void
    {
        config(['services.1xpanel.base_url' => 'https://1xpanel.test', 'services.1xpanel.key' => 'k', 'services.1xpanel.enabled' => true]);
        Http::fake();
        $order = $this->supplierOrder('1xpanel', [
            'external_status' => SupplierOrderResult::PENDING,
            'external_order_uuid' => (string) Str::uuid(),
            'external_order_id' => SupplierOrderResult::PARKED_ID_PREFIX . 'x',
        ]);
        Order::whereKey($order->id)->update(['created_at' => now()->subHours(3)]);

        Log::shouldReceive('critical')->once()->withArgs(fn ($m, $c) => in_array($order->id, $c['order_ids'] ?? [], true));
        Log::shouldReceive('info', 'warning', 'error', 'debug')->zeroOrMoreTimes();

        Artisan::call('1xpanel:check-orders');
    }

    public function test_supplier_health_lists_parked_orders(): void
    {
        $admin = new Admin();
        $admin->name = 'Edge';
        $admin->email = 'edge_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        $order = $this->supplierOrder('1xpanel', [
            'external_status' => SupplierOrderResult::PENDING,
            'external_order_uuid' => (string) Str::uuid(),
            'external_order_id' => SupplierOrderResult::PARKED_ID_PREFIX . 'x',
        ]);

        config(['services.1xpanel.enabled' => false, 'services.swift.enabled' => false]);

        $this->actingAs($admin->refresh(), 'admin')
            ->get('/' . config('hellotree.cms_route_prefix') . '/supplier-health')
            ->assertOk()
            ->assertSee('Parked orders')
            ->assertSee('#' . $order->id);
    }

    public function test_a_rejected_parked_order_leaves_the_list_and_the_alert(): void
    {
        config(['services.1xpanel.base_url' => 'https://1xpanel.test', 'services.1xpanel.key' => 'k', 'services.1xpanel.enabled' => true]);
        Http::fake();
        $order = $this->supplierOrder('1xpanel', [
            'external_status' => SupplierOrderResult::PENDING,
            'external_order_uuid' => (string) Str::uuid(),
            'external_order_id' => SupplierOrderResult::PARKED_ID_PREFIX . 'x',
            'statuses_id' => Order::STATUS_REJECTED,
            'credits_applied_status' => Order::STATUS_REJECTED,
        ]);
        Order::whereKey($order->id)->update(['created_at' => now()->subHours(3)]);

        Log::shouldReceive('critical')->never();
        Log::shouldReceive('info', 'warning', 'error', 'debug')->zeroOrMoreTimes();

        Artisan::call('1xpanel:check-orders');
    }

    // --- Yassen ----------------------------------------------------------------------

    public function test_a_yassen_reply_with_no_order_id_parks_instead_of_inviting_a_resend(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'data' => ['note' => 'accepted?']], 200)]);
        $connector = new YassenConnector(new YassenClient('https://yassen.test', 'token'));
        $order = $this->supplierOrder('yassen', ['external_order_uuid' => (string) Str::uuid()]);

        $result = $connector->placeOrder($order, ProductsVariation::find($order->product_variation_id));

        $this->assertSame(SupplierOrderResult::PENDING, $result->status);
        $this->assertSame(SupplierOrderResult::PARKED_ID_PREFIX . $order->external_order_uuid, $result->externalOrderId);
    }

    // --- Polling endpoints -----------------------------------------------------------

    public function test_the_notification_polls_are_bounded(): void
    {
        $user = $this->user();
        $rows = [];
        for ($i = 0; $i < 130; $i++) {
            $rows[] = [
                'users_id' => $user->id, 'type' => 'credit',
                'data' => json_encode(['credits_transfer_id' => $i + 1]), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        \Illuminate\Support\Facades\DB::table('user_notifications')->insert($rows);

        Sanctum::actingAs($user);

        $this->assertCount(100, $this->getJson('/api/en/user/notifications/poll')->assertOk()->json());
        $this->assertCount(100, $this->getJson('/api/en/user/notifications/credits')->assertOk()->json());
    }

    // --- Receipts --------------------------------------------------------------------

    public function test_a_customer_cannot_stack_unlimited_pending_topups(): void
    {
        Storage::fake('private');
        Mail::fake();
        $user = $this->user();
        $type = CreditsType::create(['slug' => 'edge-' . uniqid()]);
        for ($i = 0; $i < 10; $i++) {
            CreditsTransfer::create([
                'users_id' => $user->id, 'amount' => 10, 'credits_types_id' => $type->id,
                'statuses_id' => CreditsTransfer::STATUS_PENDING, 'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
                'receipt_image' => 'receipts/x.png',
            ]);
        }

        Sanctum::actingAs($user);

        $this->postJson('/api/transfer-credit-request', [
            'amount' => 5, 'credits_types_id' => $type->id, 'receipt_image' => UploadedFile::fake()->image('r.png'),
        ])->assertStatus(429);

        $this->assertSame([], Storage::disk('private')->allFiles('receipts'));
        $this->assertSame(10, CreditsTransfer::where('users_id', $user->id)->count());
    }

    public function test_a_topup_request_with_a_bad_field_stores_no_receipt(): void
    {
        Storage::fake('private');
        Mail::fake();
        $user = $this->user();
        $type = CreditsType::create(['slug' => 'edge-' . uniqid()]);
        Sanctum::actingAs($user);

        $this->postJson('/api/transfer-credit-request', [
            'amount' => 5, 'credits_types_id' => [$type->id], 'receipt_image' => UploadedFile::fake()->image('r.png'),
        ])->assertStatus(422);
        $this->postJson('/api/transfer-credit-request', [
            'amount' => 99999999999, 'credits_types_id' => $type->id, 'receipt_image' => UploadedFile::fake()->image('r.png'),
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('private')->allFiles('receipts'));
    }

    public function test_the_pending_topup_refusal_is_translated(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->assertNotSame('api.credits.too_many_pending', trans('api.credits.too_many_pending', [], $locale));
        }
    }

    // --- Cron ------------------------------------------------------------------------

    public function test_overlapping_http_cron_runs_do_not_pile_up(): void
    {
        config(['services.cron.token' => 'tok']);
        $lock = Cache::lock('cron-run:check-orders', 60);
        $this->assertTrue($lock->get());

        try {
            $this->withHeader('X-Cron-Token', 'tok')->getJson('/api/cron/suppliers/check-orders')
                ->assertOk()->assertJsonPath('skipped', 'already_running');
        } finally {
            $lock->release();
        }
    }

    public function test_the_cron_token_can_be_restricted_to_the_header(): void
    {
        config(['services.cron.token' => 'tok', 'services.cron.header_only' => true]);

        $this->getJson('/api/cron/suppliers/check-orders?token=tok')->assertStatus(403);
        $this->withHeader('X-Cron-Token', 'tok')->getJson('/api/cron/suppliers/check-orders')->assertOk();
    }

    // --- File cache ------------------------------------------------------------------

    public function test_expired_file_cache_entries_are_reclaimed(): void
    {
        config(['cache.stores.prunetest' => ['driver' => 'file', 'path' => sys_get_temp_dir() . '/prune-' . uniqid()], 'cache.default' => 'prunetest']);
        $store = Cache::store('prunetest');
        $store->put('keep', 1, 3600);
        $store->put('gone', 1, 3600);
        $path = config('cache.stores.prunetest.path');
        $gone = $store->getStore()->path('gone');
        file_put_contents($gone, (time() - 10) . substr(file_get_contents($gone), 10));

        Artisan::call('cache:prune-expired');

        $this->assertFileDoesNotExist($gone);
        $this->assertSame(1, $store->get('keep'));
        \Illuminate\Support\Facades\File::deleteDirectory($path);
    }

    // --- Third-party scripts ---------------------------------------------------------

    public function test_the_admin_loads_no_script_from_another_origin(): void
    {
        $config = config('hellotree');
        array_walk_recursive($config, function ($value) {
            if (is_string($value) && str_ends_with($value, '.js')) {
                $this->assertStringNotContainsString('//', $value);
            }
        });

        $this->assertFileExists(public_path('cms-assets/ckeditor/ckeditor.js'));
        $this->assertFileDoesNotExist(public_path('cms-assets/ckeditor/samples'));
        $this->assertStringNotContainsString('iframe.ly', file_get_contents(public_path('cms-assets/js/main.js')));
    }

    public function test_the_admin_login_page_sends_a_script_policy_with_no_outside_script_host(): void
    {
        $csp = $this->get('/' . config('hellotree.cms_route_prefix') . '/login')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self'[^;]*;/", $csp);
        $this->assertDoesNotMatchRegularExpression('/script-src[^;]*https?:/', $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_the_api_docs_page_loads_redoc_from_this_site(): void
    {
        $this->get('/api-docs')->assertOk()->assertDontSee('cdn.jsdelivr.net', false)->assertSee('openapi/redoc.standalone.js', false);
    }

    // --- IPv6 sources ----------------------------------------------------------------

    public function test_ipv6_sources_are_grouped_by_their_64_network(): void
    {
        $this->assertSame(\App\Support\ClientIp::network('2001:db8:1:1::a'), \App\Support\ClientIp::network('2001:db8:1:1:ffff::b'));
        $this->assertNotSame(\App\Support\ClientIp::network('2001:db8:1:1::a'), \App\Support\ClientIp::network('2001:db8:1:2::a'));
        $this->assertSame('203.0.113.9', \App\Support\ClientIp::network('203.0.113.9'));
    }

    public function test_a_new_address_in_the_same_ipv6_network_gets_no_new_login_budget(): void
    {
        $net = '2001:db8:' . dechex(random_int(1, 65000)) . ':1::';
        $statuses = [];
        for ($i = 1; $i <= 6; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => $net . dechex($i)])
                ->postJson('/api/en/login', ['email' => 'nobody' . uniqid() . '@example.test', 'password' => 'Wrong-Passw0rd'])->status();
        }

        $this->assertSame(429, end($statuses));
    }

    // --- Bycel exits -----------------------------------------------------------------

    private function bycelPurchase(string $state, array $extra = []): \App\BycelPurchase
    {
        $order = $this->supplierOrder('bycel', ['external_order_uuid' => (string) Str::uuid()]);

        return \App\BycelPurchase::create($extra + [
            'order_id' => $order->id, 'order_uuid' => $order->external_order_uuid, 'family' => 'recharge',
            'product_id' => '1', 'watermark_id' => 1, 'state' => $state, 'request_at' => now()->subHours(2), 'snapshot' => [],
        ]);
    }

    public function test_a_delivered_recharge_does_not_hold_up_later_vouchers(): void
    {
        $ledger = new \App\Services\Bycel\BycelPurchaseLedger();
        \App\BycelPurchase::query()->delete();
        $this->bycelPurchase(\App\BycelPurchase::STATE_DELIVERED);
        $later = $this->bycelPurchase(\App\BycelPurchase::STATE_SENT, ['request_at' => now()]);

        $this->assertFalse($ledger->hasOlderOpenIntent($later));
    }

    public function test_a_refused_first_purchase_does_not_count_as_having_bought_anything(): void
    {
        \App\BycelPurchase::query()->delete();
        $this->bycelPurchase(\App\BycelPurchase::STATE_FAILED);

        $this->assertFalse((new \App\Services\Bycel\BycelPurchaseLedger())->hasAnyPurchase());
    }

    public function test_a_completed_order_never_goes_back_to_pending(): void
    {
        config(['services.1xpanel.base_url' => 'https://1xpanel.test', 'services.1xpanel.key' => 'k', 'services.1xpanel.enabled' => true]);
        Http::fake(['*' => Http::response(['status' => 'In progress'], 200)]);
        $order = $this->supplierOrder('1xpanel', [
            'external_status' => SupplierOrderResult::COMPLETED,
            'external_order_uuid' => (string) Str::uuid(),
            'external_order_id' => '555',
        ]);

        app(\App\Services\Suppliers\SupplierOrderFulfillment::class)->refreshStatus($order);

        $this->assertSame(SupplierOrderResult::COMPLETED, $order->fresh()->external_status);
    }

    public function test_staff_cannot_abandon_a_recharge_bycel_confirmed(): void
    {
        $admin = new Admin();
        $admin->name = 'Edge';
        $admin->email = 'edge_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();
        Mail::fake();

        $purchase = $this->bycelPurchase(\App\BycelPurchase::STATE_AMBIGUOUS, ['response_result' => 'OK']);

        $this->actingAs($admin->refresh(), 'admin')
            ->put('/' . config('hellotree.cms_route_prefix') . '/supplier-health/bycel/' . $purchase->id . '/abandon');

        $this->assertSame(\App\BycelPurchase::STATE_AMBIGUOUS, $purchase->fresh()->state);
    }

    // --- Small refusals ---------------------------------------------------------------

    public function test_an_array_cron_token_is_a_clean_refusal(): void
    {
        config(['services.cron.token' => 'tok']);

        $this->getJson('/api/cron/suppliers/check-orders?token[]=tok')->assertStatus(403);
    }

    public function test_a_formula_after_a_semicolon_is_defused_and_round_trips(): void
    {
        $value = 'Netflix;=HYPERLINK("http://evil.test");b';

        $safe = \App\Services\Cms\CatalogCsv::neutralise($value);

        $this->assertStringNotContainsString(';=', $safe);
        $this->assertSame($value, \App\Services\Cms\CatalogCsv::restore($safe));
    }

    public function test_a_stale_poll_cannot_undo_a_claim_or_block_later_vouchers(): void
    {
        \App\BycelPurchase::query()->delete();
        $ledger = new \App\Services\Bycel\BycelPurchaseLedger();
        $purchase = $this->bycelPurchase(\App\BycelPurchase::STATE_SENT, ['family' => 'voucher']);
        $stale = \App\BycelPurchase::find($purchase->id);
        \App\BycelPurchase::whereKey($purchase->id)->update(['state' => \App\BycelPurchase::STATE_CLAIMED, 'merchant_purchase_id' => 7001]);

        $ledger->markAmbiguous($stale, []);

        $this->assertSame(\App\BycelPurchase::STATE_CLAIMED, $purchase->fresh()->state);

        \App\BycelPurchase::whereKey($purchase->id)->update(['state' => \App\BycelPurchase::STATE_AMBIGUOUS]);
        $later = $this->bycelPurchase(\App\BycelPurchase::STATE_SENT, ['request_at' => now()]);
        $this->assertFalse($ledger->hasOlderOpenIntent($later));
    }

    public function test_a_formula_after_a_quote_or_a_line_break_is_defused_too(): void
    {
        foreach (["a;\"=1+1\";b", "a\n=1+1", "a\r\n@cmd"] as $value) {
            $safe = \App\Services\Cms\CatalogCsv::neutralise($value);

            $this->assertDoesNotMatchRegularExpression('/[;,\t|\r\n"]\s*[=+\-@]/', $safe, $value);
            $this->assertSame($value, \App\Services\Cms\CatalogCsv::restore($safe));
        }
    }

    public function test_a_formula_after_leading_whitespace_is_defused_too(): void
    {
        $safe = \App\Services\Cms\CatalogCsv::neutralise('  =1+1');

        $this->assertStringStartsWith("'", $safe);
        $this->assertSame('  =1+1', \App\Services\Cms\CatalogCsv::restore($safe));
    }
}
