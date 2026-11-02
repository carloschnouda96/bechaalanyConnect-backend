<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\PerfectPanel\PerfectPanelClient;
use App\Services\PerfectPanel\PerfectPanelServicePage;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use Illuminate\Support\Facades\Cache;
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
 * Product type: classic `Default` SMM services are Social Media Services
 * (product_type_id = 5 — link + typed quantity, priced per 1,000); `Package`
 * services stay Direct Recharge (type 1, one package per unit).
 *
 * The API carries no description or delivery time, so a panel may opt in to
 * reading them from its public /services page (serviceDetails()).
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
        $details = $this->serviceDetails();

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
                name: $this->displayName(trim((string) ($raw['name'] ?? ('Service ' . $externalId)))),
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
                // Only when this run actually has the scraped page — an empty map
                // (opted out, or the page failed) must leave stored details alone.
                details: $details === [] ? null : $this->detailsFor($raw, $details[$externalId] ?? null),
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
        $type = isset($raw['type']) ? trim((string) $raw['type']) : '';

        return strcasecmp($type, 'Package') === 0 ? Product::TYPE_USER_ID : Product::TYPE_SOCIAL;
    }

    /** Customer-facing service name. Overridden by panels whose names carry their branding. */
    protected function displayName(string $name): string
    {
        return $name;
    }

    /**
     * Service details scraped from the panel's public /services page, keyed by
     * service id, or [] when this panel doesn't opt in (config
     * `services.{key}.scrape_details`) or the page can't be read. Cached for
     * PerfectPanelServicePage::CACHE_TTL_SECONDS because the page is tens of MB;
     * a failed read is never cached, so the next sync simply tries again.
     */
    protected function serviceDetails(): array
    {
        $key = $this->key();
        if (!config("services.{$key}.scrape_details")) {
            return [];
        }

        $cacheKey = "perfectpanel-details:{$key}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $details = app(PerfectPanelServicePage::class)->fetch((string) config("services.{$key}.base_url"), $key);
        if ($details !== []) {
            Cache::put($cacheKey, $details, PerfectPanelServicePage::CACHE_TTL_SECONDS);
        }

        return $details;
    }

    /** The scraped text for one service plus the capability flags the API itself reports. */
    private function detailsFor(array $raw, ?array $scraped): array
    {
        return array_merge(
            ['quality' => null, 'speed' => null, 'refill' => null, 'link' => null, 'start_time' => null, 'drop' => null, 'notes' => [], 'average_time' => null],
            $scraped ?? [],
            [
                'refill_available' => (bool) ($raw['refill'] ?? false),
                'cancel_available' => (bool) ($raw['cancel'] ?? false),
                'dripfeed' => (bool) ($raw['dripfeed'] ?? false),
            ],
        );
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
