<?php

namespace App\Services\Yassen;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Yassen-Card client API
 * (https://api.yassen-card.com/api-docs). Every request carries the
 * `api-token` header. Endpoints return decoded arrays; transport/auth/body
 * errors are normalised into a YassenApiException.
 */
class YassenClient
{
    private string $baseUrl;
    private ?string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.yassen.base_url'), '/');
        $this->token = $token ?? config('services.yassen.token');
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->baseUrl);
    }

    /** GET /client/api/profile — account balance + email. */
    public function profile(): array
    {
        return $this->get('/client/api/profile');
    }

    /**
     * GET /client/api/products — full catalog with pricing/availability.
     * $filters: ['products_id' => '1,2,3'] or ['base' => 1].
     */
    public function products(array $filters = []): array
    {
        return $this->get('/client/api/products', $filters);
    }

    /** GET /client/api/content/{id} — categories (id 0 = home) / category products. */
    public function content(int|string $categoryId = 0): array
    {
        return $this->get('/client/api/content/' . $categoryId);
    }

    /**
     * GET /client/api/newOrder/{productId}/params — place a supplier order.
     * $params: ['qty' => .., 'order_uuid' => uuidv4, 'playerId' => ..].
     */
    public function newOrder(int|string $productId, array $params): array
    {
        return $this->get('/client/api/newOrder/' . $productId . '/params', $params);
    }

    /**
     * GET /client/api/check?orders=ID  (or ?orders=UUID&uuid=1) — order status.
     */
    public function checkOrder(string $reference, bool $byUuid = false): array
    {
        $query = ['orders' => $reference];
        if ($byUuid) {
            $query['uuid'] = 1;
        }
        return $this->get('/client/api/check', $query);
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);

        if ($response->failed()) {
            throw new YassenApiException(
                "Yassen API request to {$path} failed: HTTP {$response->status()}",
                null,
                $response->status()
            );
        }

        $body = $response->json() ?? [];

        // The API signals in-body errors via a numeric `code` (e.g. 121 token error).
        $code = $body['code'] ?? null;
        if ($code !== null && (int) $code !== 0 && isset($body['error'])) {
            $message = $body['message'] ?? $body['error'] ?? 'Yassen API error';
            throw new YassenApiException(
                "Yassen API error on {$path}: {$message} (code {$code})",
                (int) $code,
                $response->status()
            );
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['api-token' => (string) $this->token])
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }
}
