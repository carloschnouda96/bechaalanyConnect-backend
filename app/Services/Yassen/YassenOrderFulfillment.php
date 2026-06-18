<?php

namespace App\Services\Yassen;

use App\Models\User;
use App\Order;
use App\ProductsVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Places and tracks Yassen supplier orders for local orders that reference a
 * Yassen-sourced product. Shared by FulfillYassenOrderJob (placement on
 * approval) and the yassen:check-orders command (status polling).
 *
 * Supplier statuses: accept | reject | wait. On reject the customer's credits
 * are refunded and the local order is moved to REJECTED, mirroring the
 * approved→rejected refund in Cms\OrdersController::updateUserCredits.
 */
class YassenOrderFulfillment
{
    public function __construct(private YassenClient $client)
    {
    }

    /** Whether this local order is for a Yassen-sourced product. */
    public static function isExternal(Order $order): bool
    {
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        return $variation && $variation->external_id
            && $variation->product && $variation->product->external_source === YassenCatalogSync::SOURCE;
    }

    /**
     * Place the supplier order. Idempotent: a second call for an order that
     * already has a supplier order id is a no-op.
     */
    public function fulfill(Order $order): void
    {
        if ($order->external_order_id) {
            return; // already placed
        }

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->find($order->product_variation_id);

        if (!$variation || !$variation->product || !$variation->product->external_id) {
            return; // not a supplier product
        }

        // Persist the dedupe uuid up front so a retry reuses it.
        if (!$order->external_order_uuid) {
            $order->external_order_uuid = (string) Str::uuid();
        }
        $order->external_source = YassenCatalogSync::SOURCE;
        $order->save();

        $multiplier = (float) config('services.yassen.qty_multiplier', 1);
        $params = [
            'qty' => (int) round($order->quantity * $multiplier),
            'order_uuid' => $order->external_order_uuid,
            'playerId' => $order->recipient_user,
        ];

        try {
            $response = $this->client->newOrder($variation->product->external_id, $params);
        } catch (YassenApiException $e) {
            Log::error('Yassen newOrder failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }

        $order->external_order_id = (string) ($response['order_id'] ?? $response['id'] ?? '') ?: null;
        $order->external_status = $this->normalizeStatus($response['status'] ?? null);
        $order->external_response = $response;
        $order->save();

        $this->applyStatus($order);
    }

    /** Poll the supplier for the current status of an already-placed order. */
    public function refreshStatus(Order $order): void
    {
        $reference = $order->external_order_uuid ?: $order->external_order_id;
        if (!$reference) {
            return;
        }

        $byUuid = (bool) $order->external_order_uuid;
        $response = $this->client->checkOrder($reference, $byUuid);

        $row = $this->extractOrderRow($response);
        $order->external_status = $this->normalizeStatus($row['status'] ?? $order->external_status);
        $order->external_response = $response;
        $order->save();

        $this->applyStatus($order);
    }

    /** React to a freshly stored supplier status (refund on reject). */
    private function applyStatus(Order $order): void
    {
        if ($order->external_status === 'reject' && (int) $order->statuses_id !== Order::STATUS_REJECTED) {
            $this->refund($order);
        }
    }

    private function refund(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScope('cms_draft_flag')->where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || (int) $locked->statuses_id === Order::STATUS_REJECTED) {
                return;
            }

            $user = User::where('id', $locked->users_id)->lockForUpdate()->first();
            if ($user) {
                $user->credits_balance += $locked->total_price;
                $user->total_purchases -= $locked->total_price;
                $user->save();
            }

            $locked->statuses_id = Order::STATUS_REJECTED;
            $locked->save();

            $order->statuses_id = Order::STATUS_REJECTED;
        });
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $status = strtolower(trim($status));
        return in_array($status, ['accept', 'reject', 'wait'], true) ? $status : $status;
    }

    private function extractOrderRow(array $response): array
    {
        foreach (['data', 'orders', 'result'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $value = $response[$key];
                return isset($value['status']) ? $value : (array_values($value)[0] ?? []);
            }
        }
        return $response;
    }
}
