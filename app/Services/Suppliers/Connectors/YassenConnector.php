<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierApiException;
use Illuminate\Support\Facades\Log;
use App\Services\Suppliers\SupplierIndeterminateException;
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
 *
 * Other suppliers run the same white-label platform (identical endpoints,
 * `api-token` auth, statuses and error codes): they subclass this with their own
 * KEY, which is also their `config/services.php` block (AlloSharejleConnector).
 */
class YassenConnector implements SupplierConnector
{
    public const KEY = 'yassen';

    public function __construct(private YassenClient $client)
    {
    }

    public function key(): string
    {
        return static::KEY;
    }

    /** `config('services.{key}.*')` for this supplier. */
    protected function config(string $name, $default = null)
    {
        return config('services.' . static::KEY . '.' . $name, $default);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config('enabled');
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
                name: trim((string) ($raw['name'] ?? 'Product')),
                categoryExternalId: (string) ($raw['parent_id'] ?? ''),
                categoryName: (string) ($raw['category_name'] ?? 'Category'),
                categoryImage: $this->normalizeImageUrl($raw['category_img'] ?? null),
                unitCost: (float) ($raw['price'] ?? 0),
                available: (bool) ($raw['available'] ?? true),
                productTypeId: $this->resolveProductTypeId($raw),
                qtyValues: $this->normalizeQtyValues($raw['qty_values'] ?? null),
                externalType: (string) ($raw['product_type'] ?? 'package'),
            );
        }

        return $out;
    }

    /**
     * Rebase a feed image URL onto the configured API host.
     *
     * Yassen reports `category_img` on its storefront host (yassen-card.com), which
     * is a Nuxt SPA: every /images/... path there answers 200 with the homepage HTML
     * instead of the file, so the URL looks valid but never renders. The same path on
     * the API host (api.yassen-card.com) serves the real image/webp. Verified against
     * the live feed — 6/6 sampled URLs behaved this way.
     */
    protected function normalizeImageUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $base = rtrim((string) $this->config('base_url'), '/');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if ($base === '' || $path === '' || $path === '/') {
            // Nothing to rebase onto, or a host-only URL that names no file.
            return $path === '' || $path === '/' ? null : $url;
        }

        return $base . '/' . ltrim($path, '/');
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $multiplier = (float) $this->config('qty_multiplier', 1);
        $params = [
            'qty' => (int) round($order->quantity * $multiplier),
            'order_uuid' => $order->external_order_uuid,
            'playerId' => $order->recipient_user,
        ];

        // Variation id first, product id as the fallback — matching every other
        // connector. It matters for a grouped supplier category
        // (supplier_categories.group_as_single_product), whose shared product is
        // keyed by a synthetic "group:…" id: only the variation knows the real
        // Yassen product id.
        $service = $variation->external_id ?: optional($variation->product)->external_id;

        try {
            $response = $this->client->newOrder($service, $params);
        } catch (SupplierIndeterminateException $e) {
            // Not re-sent and not refunded. This platform can be asked about the uuid, so
            // checkOrder() settles it; staff reject it if it never appears there.
            Log::critical('Supplier order outcome indeterminate — reconcile manually', [
                'order_id' => $order->id,
                'source' => $this->key(),
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: SupplierOrderResult::PARKED_ID_PREFIX . $order->external_order_uuid,
                status: SupplierOrderResult::PENDING,
                raw: ['supplier_indeterminate' => true, 'error' => $e->getMessage()],
            );
        } catch (SupplierApiException $e) {
            // A refusal the platform documents as final (no balance, bad player
            // id, product gone…) fails the order, which refunds the customer.
            // Any other answer is a refusal too, so the job may retry it.
            if ($e->apiErrorCode !== null && in_array($e->apiErrorCode, $this->finalErrorCodes(), true)) {
                return new SupplierOrderResult(
                    externalOrderId: null,
                    status: SupplierOrderResult::FAILED,
                    raw: ['error_code' => $e->apiErrorCode, 'message' => $e->getMessage()],
                );
            }
            throw $e;
        }

        // The documented reply wraps the order in `data`
        // ({"status":"OK","data":{"order_id":…,"status":"accept"}}), where the
        // top-level `status` is the envelope's, not the order's.
        $row = isset($response['data']) && is_array($response['data']) && isset($response['data']['order_id'])
            ? $response['data']
            : $response;

        $orderId = (string) ($row['order_id'] ?? $row['id'] ?? '');

        // It answered without naming an order, so it may or may not have one. A null id
        // would put this on the unplaced list, where Retry sends the purchase again.
        if ($orderId === '') {
            Log::critical('Supplier order reply has no order id — reconcile manually', [
                'order_id' => $order->id,
                'source' => $this->key(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: SupplierOrderResult::PARKED_ID_PREFIX . $order->external_order_uuid,
                status: SupplierOrderResult::PENDING,
                raw: ['supplier_indeterminate' => true] + $response,
            );
        }

        return new SupplierOrderResult(
            externalOrderId: $orderId,
            status: $this->mapStatus($row['status'] ?? null) ?? SupplierOrderResult::PENDING,
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

    /**
     * In-body error codes that mean the order was refused for good and can be
     * refunded. Empty for Yassen, which has always left a failed placement
     * Approved for an admin to look at; a subclass opts in.
     *
     * @return int[]
     */
    protected function finalErrorCodes(): array
    {
        return [];
    }

    /** accept → completed, reject → failed, wait/unknown → pending. */
    protected function mapStatus(?string $status): ?string
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
     * anything else that needs an identifier → direct recharge (1, User ID field),
     * or Coin Recharge (4) when it is an `amount` product — priced per coin, with
     * `qty` = the number of coins (Jawaker, SoulChill…).
     */
    protected function resolveProductTypeId(array $raw): int
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

        return strtolower((string) ($raw['product_type'] ?? '')) === 'amount' ? 4 : 1;
    }

    protected function normalizeQtyValues($qtyValues): ?array
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
    protected function extractList($response): array
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

    protected function extractOrderRow(array $response): array
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
