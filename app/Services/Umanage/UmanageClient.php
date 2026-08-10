<?php

namespace App\Services\Umanage;

use App\Services\Suppliers\SupplierApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the U-Manage external API
 * (https://api.umanageapp.uk/api/v1/external).
 *
 * Two things make it unlike the other supplier clients:
 *   - auth is a PAIR of headers, `X-API-Key` + `X-API-Secret`;
 *   - every path is scoped by a store id that must be discovered at runtime via
 *     GET /stores, so storeId() resolves and memoises it (config pin wins).
 *
 * Error classification matches UsharezClient's three-way split, adapted to
 * U-Manage's NESTED envelope ({"success":false,"error":{"code","message"}}):
 *
 *   SupplierApiException           retryable — 5xx, 401, 408, 429, GET transport
 *   UmanageBusinessException       definitive — other 4xx, or 200 with success:false
 *   UmanageIndeterminateException  unknown   — a POST that never came back cleanly
 *
 * Reads auto-retry; writes never do (a repeated order would double-charge the
 * wallet), so a dropped POST is reported as indeterminate.
 */
class UmanageClient
{
    /** "Ask again later" rather than "no". 429 is the documented rate-limit code. */
    private const RETRYABLE_STATUSES = [401, 408, 425, 429];

    private string $baseUrl;
    private ?string $key;
    private ?string $secret;
    private ?int $storeId = null;

    public function __construct(?string $baseUrl = null, ?string $key = null, ?string $secret = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.umanage.base_url'), '/');
        $this->key = $key ?? config('services.umanage.key');
        $this->secret = $secret ?? config('services.umanage.secret');
    }

    public function isConfigured(): bool
    {
        return !empty($this->key) && !empty($this->secret) && !empty($this->baseUrl);
    }

    /** GET /stores — the linked stores for this key pair. */
    public function stores(): array
    {
        return $this->get('/stores');
    }

    /**
     * The store every other endpoint is scoped by. A configured UMANAGE_STORE_ID
     * wins; otherwise the first linked store is used and, when the account has
     * several, they are all logged so a multi-store setup is visible rather than
     * silently half-synced.
     */
    public function storeId(): int
    {
        if ($this->storeId !== null) {
            return $this->storeId;
        }

        $pinned = config('services.umanage.store_id');
        if (is_numeric($pinned) && (int) $pinned > 0) {
            return $this->storeId = (int) $pinned;
        }

        $stores = $this->stores()['stores'] ?? [];
        if (!is_array($stores) || $stores === []) {
            throw new SupplierApiException('U-Manage returned no linked stores for this API key.');
        }

        if (count($stores) > 1) {
            Log::warning('U-Manage account has multiple stores; using the first. Set UMANAGE_STORE_ID to pin one.', [
                'stores' => array_map(
                    fn ($s) => ['store_id' => $s['store_id'] ?? null, 'store_name' => $s['store_name'] ?? null],
                    array_values($stores)
                ),
            ]);
        }

        $id = $stores[0]['store_id'] ?? null;
        if (!is_numeric($id)) {
            throw new SupplierApiException('U-Manage store list contains no usable store_id.');
        }

        return $this->storeId = (int) $id;
    }

    // ------------------------------------------------------------------- bundles

    /** GET /stores/{s}/stock — actual inventory (/bundles lists configured products only). */
    public function stock(): array
    {
        return $this->get($this->scoped('/stock'));
    }

    /** GET /stores/{s}/bundles — configured bundles, regardless of stock. */
    public function bundles(): array
    {
        return $this->get($this->scoped('/bundles'));
    }

    /** GET /stores/{s}/wallet — {"wallet":{"balance_lbp":N,…}}. */
    public function wallet(): array
    {
        return $this->get($this->scoped('/wallet'));
    }

    /** POST /stores/{s}/orders — buy a data bundle for a phone number. */
    public function createOrder(int|string $bundleId, string $secondaryNumber, ?string $reference = null): array
    {
        return $this->post($this->scoped('/orders'), array_filter([
            'bundle_id' => $bundleId,
            'secondary_number' => $secondaryNumber,
            'app_user_reference' => $reference,
        ], fn ($v) => $v !== null));
    }

    /** GET /stores/{s}/orders/{id} */
    public function order(int|string $orderId): array
    {
        return $this->get($this->scoped('/orders/' . rawurlencode((string) $orderId)));
    }

    // ----------------------------------------------------------------------- SMS

    /** GET /stores/{s}/sms-products — {credits:[…], gifts:[…]}. */
    public function smsProducts(): array
    {
        return $this->get($this->scoped('/sms-products'));
    }

    /** POST /stores/{s}/sms-orders — product_type is 'credit' or 'gift'. */
    public function createSmsOrder(
        string $productType,
        string $productCode,
        string $recipientPhone,
        string $carrierType = 'alfa',
        ?string $reference = null
    ): array {
        return $this->post($this->scoped('/sms-orders'), array_filter([
            'product_type' => $productType,
            'product_code' => $productCode,
            'recipient_phone' => $recipientPhone,
            'carrier_type' => $carrierType,
            'app_user_reference' => $reference,
        ], fn ($v) => $v !== null));
    }

    /** GET /stores/{s}/sms-orders/{id} */
    public function smsOrder(int|string $orderId): array
    {
        return $this->get($this->scoped('/sms-orders/' . rawurlencode((string) $orderId)));
    }

    // ------------------------------------------------------------------- telecom

    /** GET /stores/{s}/telecom-products */
    public function telecomProducts(): array
    {
        return $this->get($this->scoped('/telecom-products'));
    }

    /** POST /stores/{s}/telecom-orders — voucher or direct-recharge purchase. */
    public function createTelecomOrder(array $payload): array
    {
        return $this->post($this->scoped('/telecom-orders'), $payload);
    }

    /** GET /stores/{s}/telecom-orders/{id} */
    public function telecomOrder(int|string $orderId): array
    {
        return $this->get($this->scoped('/telecom-orders/' . rawurlencode((string) $orderId)));
    }

    // ----------------------------------------------------------------- transport

    private function scoped(string $path): string
    {
        return '/stores/' . $this->storeId() . $path;
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->retry(2, 500, throw: false)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new SupplierApiException("U-Manage GET {$path} failed: {$e->getMessage()}");
        }

        return $this->interpret($response, 'GET', $path, indeterminateOnUnknown: false);
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = $this->request()->timeout(60)->post($path, $body);
        } catch (ConnectionException $e) {
            throw new UmanageIndeterminateException("U-Manage POST {$path} outcome unknown: {$e->getMessage()}");
        }

        return $this->interpret($response, 'POST', $path, indeterminateOnUnknown: true);
    }

    private function interpret(Response $response, string $method, string $path, bool $indeterminateOnUnknown): array
    {
        $status = $response->status();
        $body = $response->json();
        $message = is_array($body) ? $this->errorMessage($body) : trim((string) $response->body());
        $code = is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : null;

        if ($response->failed()) {
            $text = trim("U-Manage {$method} {$path}: HTTP {$status} " . ($code ? "[{$code}] " : '') . $message);

            if ($status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true)) {
                throw new SupplierApiException($text, null, $status);
            }

            throw new UmanageBusinessException($text, is_array($body) ? $body : [], null, $status);
        }

        if (!is_array($body)) {
            $text = "U-Manage {$method} {$path}: unreadable response body";
            throw $indeterminateOnUnknown
                ? new UmanageIndeterminateException($text, null, $status)
                : new SupplierApiException($text, null, $status);
        }

        // A 200 that still reports failure — how telecom order failures arrive.
        if (array_key_exists('success', $body) && $body['success'] === false) {
            throw new UmanageBusinessException(
                trim("U-Manage {$method} {$path}: " . ($code ? "[{$code}] " : '') . ($message !== '' ? $message : 'success=false')),
                $body,
                null,
                $status
            );
        }

        return $body;
    }

    /** U-Manage nests errors under `error`, but tolerate a flat `message` too. */
    private function errorMessage(array $body): string
    {
        $error = $body['error'] ?? null;

        if (is_array($error)) {
            return (string) ($error['message'] ?? $error['code'] ?? '');
        }
        if (is_string($error) && $error !== '') {
            return $error;
        }

        return (string) ($body['message'] ?? '');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => (string) $this->key,
                'X-API-Secret' => (string) $this->secret,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
