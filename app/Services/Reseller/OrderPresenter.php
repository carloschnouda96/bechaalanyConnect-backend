<?php

namespace App\Services\Reseller;

use App\Order;

/**
 * The reseller-facing shape of an order. Shared by ResellerApiController
 * (GET/POST /v1/orders) and the order-status webhooks, so a webhook body is
 * always exactly what polling GET /v1/orders/{id} would have returned.
 *
 * Built field by field, never by serialising the model, so cost, profit and
 * supplier fields cannot leak to a reseller.
 */
class OrderPresenter
{
    public static function present(Order $order): array
    {
        $order->loadMissing('product_variation.product');
        $variation = $order->product_variation;

        return [
            'id' => $order->id,
            'client_order_id' => $order->client_order_id,
            'status' => self::statusName((int) $order->statuses_id),
            'variation_id' => $order->product_variation_id,
            'product_name' => $variation?->product?->name,
            'variation_name' => $variation?->name,
            'quantity' => (int) $order->quantity,
            'total_price' => round((float) $order->total_price, 2),
            'recipient_user' => $order->recipient_user,
            'recipient_phone_number' => $order->recipient_phone_number,
            // Codes are only released once the order is completed, exactly as the
            // storefront shows them.
            'codes' => (int) $order->statuses_id === Order::STATUS_APPROVED ? self::codes($order->code) : [],
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
        ];
    }

    public static function statusNames(): array
    {
        return [
            Order::STATUS_APPROVED => 'completed',
            Order::STATUS_REJECTED => 'rejected',
            Order::STATUS_PENDING => 'pending',
        ];
    }

    public static function statusName(int $statusId): string
    {
        return self::statusNames()[$statusId] ?? 'pending';
    }

    /** `orders.code` is HTML with one `<li>` per code (see order-codes.tsx). */
    private static function codes(?string $html): array
    {
        if (blank($html)) {
            return [];
        }

        preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $m);

        return array_values(array_filter(array_map(
            fn ($c) => trim(html_entity_decode(strip_tags($c))),
            $m[1]
        ), fn ($c) => $c !== ''));
    }
}
