<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\PerfectPanel\PerfectPanelClient;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use Illuminate\Support\Str;

/**
 * Shared adapter for every Perfect Panel v2 SMM panel (1xpanel and the future
 * panels). A concrete subclass only declares its KEY/key(); all the protocol
 * behaviour lives here, mirroring SwiftConnector (which intentionally stays its
 * own untouched implementation):
 *   - cost: `Package` services charge a flat `rate` per unit; classic `Default`
 *     SMM services charge `rate` per 1000 (see unitCost()).
 *   - availability = presence in the services list (no `available` flag).
 *   - categories are grouped by a slug of the `category` string (no numeric id).
 *   - the recipient maps to the supplier `link` field.
 *   - status vocabulary differs and NO delivered code is returned, so orders are
 *     status-tracking only: Completed/In progress/Awaiting/Partial stay APPROVED,
 *     Canceled/Fail → refund + REJECTED.
 *
 * Config is read from `services.{key}.{base_url,key,enabled}` and the HTTP client
 * is built per connector (no container auto-wiring needed, since each supplier
 * needs a different base URL + key).
 *
 * All synced Perfect Panel products are product_type_id = 1 (recipient/link field).
 */
abstract class PerfectPanelConnector implements SupplierConnector
{
    private ?PerfectPanelClient $client = null;

    /** Stable key stored in external_source; also the `services.{key}.*` config namespace. */
    abstract public function key(): string;

    public function isEnabled(): bool
    {
        return (bool) config("services.{$this->key()}.enabled");
    }

    public function isConfigured(): bool
    {
        return $this->client()->isConfigured();
    }

    /** Lazily build (and cache) the HTTP client from this supplier's config block. */
    protected function client(): PerfectPanelClient
    {
        if ($this->client === null) {
            $key = $this->key();
            $this->client = new PerfectPanelClient(
                (string) config("services.{$key}.base_url"),
                config("services.{$key}.key"),
                ucfirst($key),
            );
        }

        return $this->client;
    }

    public function fetchCatalog(): array
    {
        $out = [];
        foreach ($this->client()->services() as $raw) {
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
                productTypeId: $this->productTypeId($raw),
                qtyValues: $this->qtyValues($raw['min'] ?? null, $raw['max'] ?? null),
                externalType: $type,
            );
        }

        return $out;
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $service = $variation->external_id ?: $variation->product->external_id;

        $response = $this->client()->addOrder(
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
        $response = $this->client()->status((string) $order->external_order_id);

        return new SupplierOrderResult(
            externalOrderId: $order->external_order_id,
            status: $this->mapStatus($response['status'] ?? null) ?? ($order->external_status ?: SupplierOrderResult::PENDING),
            raw: $response,
        );
    }

    public function balance(): ?float
    {
        try {
            $response = $this->client()->balance();
        } catch (\Throwable $e) {
            return null;
        }

        return isset($response['balance']) && is_numeric($response['balance'])
            ? (float) $response['balance']
            : null;
    }

    /**
     * Local product_type_id driving the storefront purchase form. Perfect Panel
     * services collect a `link`, so the recipient field is used (type 1).
     * Overridable for panels that need a different mapping.
     */
    protected function productTypeId(array $raw): int
    {
        return 1;
    }

    /**
     * Status ∈ In progress | Awaiting | Partial | Completed | Canceled | Fail.
     * Completed → completed; Canceled/Fail → failed; everything in-flight → pending.
     */
    protected function mapStatus(?string $status): ?string
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
     * accounts) charge a flat `rate` for one package, so the unit cost IS the
     * rate. Classic "Default" SMM services price `rate` per 1000 units, so divide.
     */
    protected function unitCost(float $rate, ?string $type): float
    {
        if ($type !== null && strcasecmp(trim($type), 'Package') === 0) {
            return $rate;
        }
        return $rate / 1000;
    }

    protected function qtyValues($min, $max): ?array
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
