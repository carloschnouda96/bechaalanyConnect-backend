<?php

namespace App\Services\Swift;

use App\Services\Suppliers\SupplierApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the SwiftServices API (Perfect Panel v2,
 * https://swiftservices.store). Unlike Yassen, auth is a `key` query parameter
 * (not a header) and every action hits the single `/api/v2` endpoint with an
 * `action` param. Transport failures and error bodies (a bare string or an
 * `{error: …}` object) are normalised into a SupplierApiException.
 */
class SwiftClient
{
    private string $baseUrl;
    private ?string $key;

    public function __construct(?string $baseUrl = null, ?string $key = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.swift.base_url'), '/');
        $this->key = $key ?? config('services.swift.key');
    }

    public function isConfigured(): bool
    {
        return !empty($this->key) && !empty($this->baseUrl);
    }

    /** action=services — full service catalog (a flat JSON list). */
    public function services(): array
    {
        return $this->call(['action' => 'services']);
    }

    /** action=add — place an order. `link` is required by the API. */
    public function addOrder(int|string $service, string $link, int $quantity): array
    {
        return $this->call([
            'action' => 'add',
            'service' => $service,
            'link' => $link,
            'quantity' => $quantity,
        ]);
    }

    /** action=status — status of a single placed order. */
    public function status(int|string $orderId): array
    {
        return $this->call(['action' => 'status', 'order' => $orderId]);
    }

    /** action=balance — {balance, currency}. */
    public function balance(): array
    {
        return $this->call(['action' => 'balance']);
    }

    /** action=cancel — request cancellation of an order. */
    public function cancel(int|string $orderId): array
    {
        return $this->call(['action' => 'cancel', 'order' => $orderId]);
    }

    private function call(array $params): array
    {
        $action = (string) ($params['action'] ?? '');
        $params['key'] = (string) $this->key;

        $response = $this->request()->get('/api/v2', $params);

        if ($response->failed()) {
            throw new SupplierApiException(
                "Swift API request ({$action}) failed: HTTP {$response->status()}",
                null,
                $response->status()
            );
        }

        $body = $response->json();

        // Perfect Panel signals errors with a bare string or an {error: …} object.
        if ($body === null) {
            $raw = trim($response->body());
            if ($raw !== '') {
                throw new SupplierApiException("Swift API error ({$action}): {$raw}");
            }
            return [];
        }
        if (is_string($body)) {
            throw new SupplierApiException("Swift API error ({$action}): {$body}");
        }
        if (is_array($body) && isset($body['error'])) {
            $message = is_string($body['error']) ? $body['error'] : json_encode($body['error']);
            throw new SupplierApiException("Swift API error ({$action}): {$message}");
        }

        return is_array($body) ? $body : [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }
}
