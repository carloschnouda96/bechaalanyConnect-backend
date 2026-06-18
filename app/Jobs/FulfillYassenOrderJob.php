<?php

namespace App\Jobs;

use App\Order;
use App\Services\Yassen\YassenOrderFulfillment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Places the supplier order for a local order once an admin approves it.
 * Dispatched from Cms\OrdersController on the PENDING→APPROVED transition.
 *
 * With QUEUE_CONNECTION=sync (the current default) this runs inline during the
 * approve request; set a real queue driver in production so the Yassen HTTP
 * call doesn't block the CMS response.
 */
class FulfillYassenOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $orderId)
    {
    }

    public function handle(YassenOrderFulfillment $fulfillment): void
    {
        $order = Order::withoutGlobalScope('cms_draft_flag')->find($this->orderId);
        if (!$order || (int) $order->statuses_id !== Order::STATUS_APPROVED) {
            return;
        }

        $fulfillment->fulfill($order);
    }
}
