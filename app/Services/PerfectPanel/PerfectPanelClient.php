<?php

namespace App\Services\PerfectPanel;

use App\Services\Suppliers\SupplierApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reusable HTTP wrapper for any Perfect Panel v2 SMM panel (1xpanel and the
 * other social-service panels coming next). Every panel exposes the same shape:
 * a single `/api/v2` endpoint, `key` query-param auth (not a header), and an
 * `action` param selecting the operation. Transport failures and error bodies
 * (a bare string or an `{error: …}` object) are normalised into a
 * SupplierApiException.
 *
 * Unlike SwiftClient (which is bound to the `services.swift.*` config), this
 * client is constructed with an explicit base URL + key + label, so one class
 * serves every Perfect Panel supplier. The `$label` only flavours error
 * messages (e.g. "1xpanel API error (...)").
 */
class PerfectPanelClient
{
    private string $baseUrl;
    private ?string $key;
    private string $label;

    public function __construct(string $baseUrl, ?string $key, string $label = 'Perfect Panel')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->key = $key;
        $this->label = $label;
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
        return $this->call(['action' => 'cancel', 'orders' => $orderId]);
    }

    private const WRITE_ACTIONS = ['add', 'cancel', 'refill'];

    private function call(array $params): array
    {
        $action = (string) ($params['action'] ?? '');
        $params['key'] = (string) $this->key;

        $response = in_array($action, self::WRITE_ACTIONS, true)
            ? $this->request()->post('/api/v2', $params)
            : $this->request()->get('/api/v2', $params);

        if ($response->failed()) {
            throw new SupplierApiException(
                "{$this->label} API request ({$action}) failed: HTTP {$response->status()}",
                null,
                $response->status()
            );
        }

        $body = $response->json();

        // Perfect Panel signals errors with a bare string or an {error: …} object.
        if ($body === null) {
            $raw = trim($response->body());
            if ($raw !== '') {
                throw new SupplierApiException("{$this->label} API error ({$action}): {$raw}");
            }
            return [];
        }
        if (is_string($body)) {
            throw new SupplierApiException("{$this->label} API error ({$action}): {$body}");
        }
        if (is_array($body) && isset($body['error'])) {
            $message = is_string($body['error']) ? $body['error'] : json_encode($body['error']);
            throw new SupplierApiException("{$this->label} API error ({$action}): {$message}");
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
