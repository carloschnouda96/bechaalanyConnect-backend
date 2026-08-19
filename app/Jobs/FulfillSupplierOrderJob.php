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
        /*
         * Never run before the transaction that dispatched this job has committed.
         *
         * Cms\OrdersController approves the order and moves credits inside a
         * DB::transaction. Without this, the job can start while that transaction is
         * still open, read the pre-approval row, decide there is nothing to do and
         * exit — leaving an order the customer paid for that is never placed with the
         * supplier.
         *
         * Set through Queueable::afterCommit() rather than by declaring a property:
         * the trait already declares `public $afterCommit;` with no default, and PHP
         * treats any redeclaration with a different default as an incompatible
         * composition — a fatal error the moment the class is autoloaded.
         */
        $this->afterCommit();
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
