<?php

namespace App\Services\Reseller;

use App\ApiWebhook;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends one signed POST to a reseller's webhook endpoint. Used by
 * DeliverOrderWebhookJob (real events, with retries) and by the "send test
 * event" button on /account-dashboard/api-keys (one attempt, inline).
 *
 * Signature (documented in public/openapi/reseller-v1.yaml):
 *
 *   X-Bechaalany-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * The timestamp is inside the signed string so a captured delivery cannot be
 * replayed later; it is per attempt, the body is not — a retry carries the
 * same body and event id.
 */
class WebhookSender
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(private WebhookUrlGuard $guard)
    {
    }

    /**
     * @throws WebhookUrlRejected the URL is (now) unsafe or unresolvable
     * @throws \Illuminate\Http\Client\ConnectionException no response at all
     */
    public function send(ApiWebhook $webhook, string $eventId, string $eventType, string $body): Response
    {
        $target = $this->guard->resolve($webhook->url);
        $timestamp = time();

        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        return Http::withHeaders([
                'User-Agent' => 'BechaalanyConnect-Webhooks/1.0',
                'X-Bechaalany-Event' => $eventType,
                'X-Bechaalany-Event-Id' => $eventId,
                'X-Bechaalany-Signature' => self::signatureHeader((string) $webhook->secret, $timestamp, $body),
            ])
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            // A redirect would be a second, unchecked request — to anywhere.
            ->withoutRedirecting()
            // Connect to the address the guard approved, not to whatever a second
            // DNS lookup returns (rebinding). TLS still verifies against the host name.
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]]])
            ->withBody($body, 'application/json')
            ->post($webhook->url);
    }

    public static function signatureHeader(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
