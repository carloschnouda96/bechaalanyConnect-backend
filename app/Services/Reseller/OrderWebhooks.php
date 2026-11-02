<?php

namespace App\Services\Reseller;

use App\ApiWebhook;
use App\Jobs\DeliverOrderWebhookJob;
use App\Models\User;
use App\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Queues the order-status webhook for an API order that just reached a final
 * state. Callers decide WHEN an order is final; this decides WHETHER anyone is
 * told:
 *
 *   - Cms\OrdersController::notify()   admin approve / reject (but not an approve
 *                                      that hands a supplier order to fulfillment —
 *                                      that one completes when the supplier does)
 *   - SupplierOrderFulfillment         supplier reported completed, or failed and
 *                                      the order was refunded + rejected
 *
 * Only `source = 'api'` orders, only while the reseller still has API access and
 * a webhook set. Call it AFTER the status change has committed; the job is also
 * afterCommit, so a call made inside a transaction is held until it commits.
 *
 * Never throws: the order and its money are already settled, and a webhook
 * problem must not be able to fail a supplier poll or an admin's save.
 */
class OrderWebhooks
{
    public const EVENT_COMPLETED = 'order.completed';
    public const EVENT_REJECTED = 'order.rejected';

    public static function orderFinalized(int $orderId): void
    {
        try {
            $order = Order::withoutGlobalScope('cms_draft_flag')->find($orderId);

            if (!$order || $order->source !== 'api') {
                return;
            }

            $event = match ((int) $order->statuses_id) {
                Order::STATUS_APPROVED => self::EVENT_COMPLETED,
                Order::STATUS_REJECTED => self::EVENT_REJECTED,
                default => null,
            };

            if (!$event || !User::where('id', $order->users_id)->where('api_enabled', 1)->exists()) {
                return;
            }

            if (!ApiWebhook::where('user_id', $order->users_id)->exists()) {
                return;
            }

            $eventId = 'evt_' . Str::uuid()->getHex();

            // Snapshot now: a retry hours later re-sends what was true when the
            // event happened, with the same id, rather than a newer state.
            $body = json_encode([
                'id' => $eventId,
                'type' => $event,
                'created_at' => now()->toIso8601String(),
                'data' => OrderPresenter::present($order),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            DeliverOrderWebhookJob::dispatch((int) $order->users_id, $eventId, $event, $body);
        } catch (\Throwable $e) {
            Log::error('Order webhook could not be queued', ['order_id' => $orderId, 'exception' => $e]);
        }
    }
}
