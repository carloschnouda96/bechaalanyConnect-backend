<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Services\Yassen\YassenClient;
use Illuminate\Support\Arr;

/**
 * Yassen-Card adapter. Wraps the unchanged YassenClient and holds the
 * Yassen-specific mapping that previously lived in YassenCatalogSync
 * (catalog → DTO) and YassenOrderFulfillment (newOrder / check payloads).
 *
 * Pricing is per-unit (the supplier `price` is the unit cost). The recipient
 * field is Yassen's `playerId`. Availability comes from the `available` flag.
 * Status map: accept → completed, wait → pending, reject → failed.
 */
class YassenConnector implements SupplierConnector
{
    public const KEY = 'yassen';

    public function __construct(private YassenClient $client)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.yassen.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function fetchCatalog(): array
    {
        $rows = $this->extractList($this->client->products());

        $out = [];
        foreach ($rows as $raw) {
            $externalId = (string) ($raw['id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $out[] = new SupplierProduct(
                externalId: $externalId,
                name: trim((string) ($raw['name'] ?? ('Product ' . $externalId))),
                categoryExternalId: (string) ($raw['parent_id'] ?? ''),
                categoryName: (string) ($raw['category_name'] ?? ('Category ' . ($raw['parent_id'] ?? ''))),
                categoryImage: $raw['category_img'] ?? null,
                unitCost: (float) ($raw['price'] ?? 0),
                available: (bool) ($raw['available'] ?? true),
                productTypeId: $this->resolveProductTypeId($raw),
                qtyValues: $this->normalizeQtyValues($raw['qty_values'] ?? null),
                externalType: (string) ($raw['product_type'] ?? 'package'),
            );
        }

        return $out;
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $multiplier = (float) config('services.yassen.qty_multiplier', 1);
        $params = [
            'qty' => (int) round($order->quantity * $multiplier),
            'order_uuid' => $order->external_order_uuid,
            'playerId' => $order->recipient_user,
        ];

        $response = $this->client->newOrder($variation->product->external_id, $params);

        return new SupplierOrderResult(
            externalOrderId: (string) ($response['order_id'] ?? $response['id'] ?? '') ?: null,
            status: $this->mapStatus($response['status'] ?? null) ?? SupplierOrderResult::PENDING,
            raw: $response,
        );
    }

    public function checkOrder(Order $order): SupplierOrderResult
    {
        $reference = $order->external_order_uuid ?: $order->external_order_id;
        $byUuid = (bool) $order->external_order_uuid;

        $response = $this->client->checkOrder((string) $reference, $byUuid);
        $row = $this->extractOrderRow($response);

        return new SupplierOrderResult(
            externalOrderId: $order->external_order_id,
            status: $this->mapStatus($row['status'] ?? null) ?? ($order->external_status ?: SupplierOrderResult::PENDING),
            raw: $response,
        );
    }

    public function balance(): ?float
    {
        try {
            $profile = $this->client->profile();
        } catch (\Throwable $e) {
            return null;
        }

        foreach (['balance', 'credit', 'amount'] as $key) {
            if (isset($profile[$key]) && is_numeric($profile[$key])) {
                return (float) $profile[$key];
            }
            if (isset($profile['data'][$key]) && is_numeric($profile['data'][$key])) {
                return (float) $profile['data'][$key];
            }
        }

        return null;
    }

    /** accept → completed, reject → failed, wait/unknown → pending. */
    private function mapStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match (strtolower(trim($status))) {
            'accept' => SupplierOrderResult::COMPLETED,
            'reject' => SupplierOrderResult::FAILED,
            'wait' => SupplierOrderResult::PENDING,
            default => SupplierOrderResult::PENDING,
        };
    }

    /**
     * Pick the local product_type_id from the supplier's `params` (the inputs the
     * order requires): no input → code/card (2); a phone hint → telecom charge (3);
     * anything else that needs an identifier → direct recharge (1, User ID field).
     */
    private function resolveProductTypeId(array $raw): int
    {
        $params = $raw['params'] ?? [];
        if (empty($params)) {
            return 2;
        }

        $text = mb_strtolower(implode(' ', (array) $params));
        foreach (['هاتف', 'جوال', 'موبايل', 'phone', 'mobile'] as $hint) {
            if (mb_strpos($text, $hint) !== false) {
                return 3;
            }
        }

        return 1;
    }

    private function normalizeQtyValues($qtyValues): ?array
    {
        if ($qtyValues === null || $qtyValues === '') {
            return null;
        }
        if (is_array($qtyValues)) {
            return $qtyValues;
        }
        return ['value' => $qtyValues];
    }

    /** Yassen list endpoints may wrap rows under data/products; flatten to a list. */
    private function extractList($response): array
    {
        if (!is_array($response)) {
            return [];
        }
        foreach (['data', 'products', 'result', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }
        return Arr::isList($response) ? $response : [$response];
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
