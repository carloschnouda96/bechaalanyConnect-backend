<?php

namespace Tests\Feature\Suppliers;

use App\CreditLedgerEntry;
use App\Models\User;
use App\Order;
use App\Services\Suppliers\Connectors\UsharezConnector;
use App\Services\Suppliers\SupplierOrderFulfillment;
use App\Services\Suppliers\SupplierOrderResult;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * usharez API v2 end to end through the real engine: an approved quota order is
 * placed as an async job (stays Approved, external pending), and the poll then
 * either delivers it or refunds it — exactly once.
 */
class UsharezAsyncFulfillmentTest extends TestCase
{
    use CreatesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.usharez.base_url' => 'https://bechaalanyconnect.usharez.com',
            'services.usharez.token' => 'test-token',
            'services.usharez.enabled' => true,
            'services.usharez.quota_async' => true,
            'services.usharez.phone_format' => 'national8',
        ]);
    }

    private function approvedQuotaOrder(): Order
    {
        $variation = $this->createVariation(price: 8.00, productTypeId: 3);
        $variation->product->update(['external_source' => UsharezConnector::KEY, 'external_id' => 'group:usharez-quota']);
        $variation->update(['external_id' => 'quota:11']);

        $user = User::create([
            'username' => 'ush_' . uniqid(),
            'email' => 'ush_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 8.00,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $variation->id,
            'quantity' => 1,
            'total_price' => 8.00,
            'recipient_phone_number' => '03123456',
            'statuses_id' => Order::STATUS_APPROVED,
            'credits_applied_status' => Order::STATUS_APPROVED,
        ]);
    }

    private function jobStatus(string $status, array $data = []): array
    {
        return [
            'success' => true,
            'jobId' => 'JOB_9',
            'status' => $status,
            'data' => $data + ['allocated_admin' => null, 'error' => null, 'refunded' => false],
        ];
    }

    /**
     * Http::fake() stubs accumulate and the first match wins, so each test fakes
     * both endpoints once: the allocate POST, then the job status it will poll.
     */
    private function place(Order $order, array $jobStatus): Order
    {
        Http::fake([
            '*/api/v2/quota/admin/allocate' => Http::response(['success' => true, 'jobId' => 'JOB_9', 'status' => 'queued'], 202),
            '*/api/v2/quota/admin/status/JOB_9' => Http::response($jobStatus, 200),
        ]);
        app(SupplierOrderFulfillment::class)->fulfill($order);

        return $order->refresh();
    }

    public function test_placement_leaves_the_order_approved_and_pollable(): void
    {
        $order = $this->place($this->approvedQuotaOrder(), $this->jobStatus('queued'));

        $this->assertSame(Order::STATUS_APPROVED, (int) $order->statuses_id);
        $this->assertSame('quota:JOB_9', $order->external_order_id);
        $this->assertSame(SupplierOrderResult::PENDING, $order->external_status);
    }

    public function test_succeeded_job_completes_and_delivers_the_admin_number(): void
    {
        $order = $this->place($this->approvedQuotaOrder(), $this->jobStatus('succeeded', [
            'allocated_admin' => ['phone_number' => '03987654'],
        ]));

        app(SupplierOrderFulfillment::class)->refreshStatus($order);
        $order->refresh();

        $this->assertSame(SupplierOrderResult::COMPLETED, $order->external_status);
        $this->assertSame(Order::STATUS_APPROVED, (int) $order->statuses_id);
        $this->assertStringContainsString('<li>03987654</li>', $order->code);
    }

    public function test_rejected_job_refunds_the_customer_exactly_once(): void
    {
        $order = $this->place($this->approvedQuotaOrder(), $this->jobStatus('rejected', [
            'error' => 'Line not eligible',
            'refunded' => true,
        ]));

        $fulfillment = app(SupplierOrderFulfillment::class);
        $fulfillment->refreshStatus($order);
        $fulfillment->refreshStatus($order->refresh());
        $order->refresh();

        $this->assertSame(Order::STATUS_REJECTED, (int) $order->statuses_id);
        $this->assertSame(SupplierOrderResult::FAILED, $order->external_status);
        $this->assertSame(1, CreditLedgerEntry::where('user_id', $order->users_id)
            ->where('reason', CreditLedgerEntry::REASON_SUPPLIER_REFUND)
            ->count());
        $this->assertEquals(8.00, (float) User::find($order->users_id)->credits_balance);
    }
}
