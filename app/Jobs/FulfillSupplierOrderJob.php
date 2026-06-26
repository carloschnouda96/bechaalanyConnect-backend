<?php

namespace App\Jobs;

use App\Order;
use App\Services\Suppliers\SupplierOrderFulfillment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Places the supplier order for a local order once an admin approves it.
 * Dispatched from Cms\OrdersController on the PENDING→APPROVED transition,
 * for any supplier (the connector is resolved from the order's external_source).
 *
 * With QUEUE_CONNECTION=sync (the current default) this runs inline during the
 * approve request; set a real queue driver in production so the supplier HTTP
 * call doesn't block the CMS response.
 */
class FulfillSupplierOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $orderId)
    {
    }

    public function handle(SupplierOrderFulfillment $fulfillment): void
    {
        $order = Order::withoutGlobalScope('cms_draft_flag')->find($this->orderId);
        if (!$order || (int) $order->statuses_id !== Order::STATUS_APPROVED) {
            return;
        }

        $fulfillment->fulfill($order);
    }
}
