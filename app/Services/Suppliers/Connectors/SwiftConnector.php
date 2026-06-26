<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Services\Swift\SwiftClient;
use Illuminate\Support\Str;

/**
 * SwiftServices adapter (Perfect Panel v2). Absorbs the differences from Yassen:
 *   - cost: `Package` services charge a flat `rate` per unit; `Default` SMM
 *     services charge `rate` per 1000 (see unitCost()). This catalog is all
 *     Package, so the unit cost is the rate as-is.
 *   - availability = presence in the services list (no `available` flag)
 *   - categories are grouped by the `category` string (no numeric id)
 *   - the recipient maps to the supplier `link` field
 *   - status vocabulary differs and NO delivered code is returned, so orders are
 *     status-tracking only: Completed/In progress/Awaiting/Partial stay APPROVED,
 *     Canceled/Fail → refund + REJECTED.
 *
 * All synced Swift products are product_type_id = 1 (recipient/link field).
 */
class SwiftConnector implements SupplierConnector
{
    public const KEY = 'swift';

    public function __construct(private SwiftClient $client)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.swift.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function fetchCatalog(): array
    {
        $out = [];
        foreach ($this->client->services() as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $externalId = (string) ($raw['service'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $category = trim((string) ($raw['category'] ?? '')) ?: 'Uncategorized';
            $type = isset($raw['type']) ? (string) $raw['type'] : null;

            $out[] = new SupplierProduct(
                externalId: $externalId,
                name: trim((string) ($raw['name'] ?? ('Service ' . $externalId))),
                // No category id in Perfect Panel — group by a slug of the name.
                categoryExternalId: Str::slug($category) ?: 'uncategorized',
                categoryName: $category,
                categoryImage: null,
                unitCost: $this->unitCost((float) ($raw['rate'] ?? 0), $type),
                // Presence in the list implies availability (no `available` flag).
                available: true,
                productTypeId: 1,
                qtyValues: $this->qtyValues($raw['min'] ?? null, $raw['max'] ?? null),
                externalType: $type,
            );
        }

        return $out;
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $service = $variation->external_id ?: $variation->product->external_id;

        $response = $this->client->addOrder(
            $service,
            (string) $order->recipient_user,
            (int) $order->quantity,
        );

        return new SupplierOrderResult(
            externalOrderId: isset($response['order']) ? (string) $response['order'] : null,
            status: SupplierOrderResult::PENDING,
            raw: $response,
        );
    }

    public function checkOrder(Order $order): SupplierOrderResult
    {
        $response = $this->client->status((string) $order->external_order_id);

        return new SupplierOrderResult(
            externalOrderId: $order->external_order_id,
            status: $this->mapStatus($response['status'] ?? null) ?? ($order->external_status ?: SupplierOrderResult::PENDING),
            raw: $response,
        );
    }

    public function balance(): ?float
    {
        try {
            $response = $this->client->balance();
        } catch (\Throwable $e) {
            return null;
        }

        return isset($response['balance']) && is_numeric($response['balance'])
            ? (float) $response['balance']
            : null;
    }

    /**
     * Status ∈ In progress | Awaiting | Partial | Completed | Canceled | Fail.
     * Completed → completed; Canceled/Fail → failed; everything in-flight → pending.
     */
    private function mapStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match (strtolower(trim($status))) {
            'completed' => SupplierOrderResult::COMPLETED,
            'canceled', 'cancelled', 'fail', 'failed' => SupplierOrderResult::FAILED,
            default => SupplierOrderResult::PENDING, // In progress, Awaiting, Partial, …
        };
    }

    /**
     * Per-single-unit cost. Perfect Panel "Package" services (subscriptions,
     * accounts — the whole Swift catalog here) charge a flat `rate` for one
     * package, so the unit cost IS the rate. Classic "Default" SMM services price
     * `rate` per 1000 units, so divide. Confirmed against live data: every Swift
     * service is Package with rates like $8 (Netflix) / $19.80 (Anghami) — `/1000`
     * would have priced them at sub-cent.
     */
    private function unitCost(float $rate, ?string $type): float
    {
        if ($type !== null && strcasecmp(trim($type), 'Package') === 0) {
            return $rate;
        }
        return $rate / 1000;
    }

    private function qtyValues($min, $max): ?array
    {
        if ($min === null && $max === null) {
            return null;
        }
        return [
            'min' => $min !== null ? (int) $min : null,
            'max' => $max !== null ? (int) $max : null,
        ];
    }
}
