<?php

namespace App\Services\Usharez;

use App\Services\Suppliers\SupplierApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the usharez API (https://bechaalanyconnect.usharez.com).
 * Auth is an `Authorization: Bearer <token>` header, like Yassen (Swift/1xpanel
 * use a `key` query param instead).
 *
 * This client owns the error CLASSIFICATION for the whole integration, so the
 * connector stays pure mapping. Three outcomes:
 *
 *   SupplierApiException          retryable — 5xx, 401, 408/425/429, GET transport
 *   UsharezBusinessException      definitive — other 4xx, or 200 with success:false
 *   UsharezIndeterminateException unknown   — a POST that never came back cleanly
 *
 * Reads are auto-retried (idempotent). Writes are NOT: re-sending an allocate
 * would charge the account twice, so a dropped POST is reported as indeterminate
 * rather than retried or failed.
 *
 * Quota allocation has two versions. v2 (the default) is async: the POST answers
 * 202 with a jobId and GET /api/v2/quota/admin/status/{jobId} is polled until the
 * job is terminal. v1 is the old synchronous call, kept only as the rollback path
 * behind services.usharez.quota_async — it could time out while Alfa was slow.
 */
class UsharezClient
{
    /**
     * Statuses that mean "ask again later" rather than "no". 401 is deliberately
     * in here: an expired/revoked token must not look like a customer-facing
     * rejection, or a bad deploy would mass-refund every pending order.
     */
    private const RETRYABLE_STATUSES = [401, 408, 425, 429];

    private string $baseUrl;
    private ?string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.usharez.base_url'), '/');
        $this->token = $token ?? config('services.usharez.token');
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->baseUrl);
    }

    /** GET /api/user/balance — {"balance":N} (+ "debt_allowance" when one exists). LBP. */
    public function balance(): array
    {
        return $this->get('/api/user/balance');
    }

    /** GET /api/quota/admin/stock — {"success":true,"data":[{quota_size,price},…]}. Prices in LBP. */
    public function quotaStock(): array
    {
        return $this->get('/api/quota/admin/stock');
    }

    /**
     * GET /api/alfa-gifts — priced Alfa Gift services.
     * Shape UNVERIFIED: the live token currently gets HTTP 403 on this route, so
     * the connector parses it tolerantly.
     */
    public function alfaGifts(): array
    {
        return $this->get('/api/alfa-gifts');
    }

    /** POST /api/quota/admin/allocate (v1) — synchronous; returns no order id. Rollback path only. */
    public function allocateQuota(string $phoneNumber, int|float $quotaSize): array
    {
        return $this->post('/api/quota/admin/allocate', [
            'phoneNumber' => $phoneNumber,
            'quotaSize' => $quotaSize,
        ]);
    }

    /**
     * POST /api/v2/quota/admin/allocate — async. HTTP 202
     * {"success":true,"jobId":"…","status":"queued","pollUrl":"…","data":{…}}.
     * Fast-fail errors (bad number, no quota, insufficient balance, phone busy)
     * still come back immediately as a 4xx / success:false.
     */
    public function allocateQuotaAsync(string $phoneNumber, int|float $quotaSize): array
    {
        return $this->post('/api/v2/quota/admin/allocate', [
            'phoneNumber' => $phoneNumber,
            'quotaSize' => $quotaSize,
        ]);
    }

    /**
     * GET /api/v2/quota/admin/status/{jobId} —
     * {"success":true,"jobId":"…","status":"succeeded","data":{"allocated_admin":{…},"error":null,"refunded":false}}.
     *
     * The success:false envelope check is OFF here: a body describing a rejected
     * job is an answer about the job, not a failed request, so it is handed back
     * for the connector to map by `status`. HTTP errors are classified as usual.
     */
    public function quotaJobStatus(string $jobId): array
    {
        return $this->get('/api/v2/quota/admin/status/' . rawurlencode($jobId), envelopeCheck: false);
    }

    /** POST /api/alfa-gifts/purchase — `service` is the gift's NAME, not an id. */
    public function purchaseGift(string $service, string $recipientPhoneNumber, int $qty): array
    {
        return $this->post('/api/alfa-gifts/purchase', [
            'service' => $service,
            'recipient_phone_number' => $recipientPhoneNumber,
            'qty' => $qty,
        ]);
    }

    // ------------------------------------------------------------------ transport

    /** Reads are idempotent, so they auto-retry and are never "indeterminate". */
    private function get(string $path, array $query = [], bool $envelopeCheck = true): array
    {
        try {
            $response = $this->request()->retry(2, 500, throw: false)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new SupplierApiException("Usharez GET {$path} failed: {$e->getMessage()}");
        }

        return $this->interpret($response, 'GET', $path, indeterminateOnUnknown: false, envelopeCheck: $envelopeCheck);
    }

    /** Writes never auto-retry — a repeated allocate would double-charge. */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->request()->timeout(45)->post($path, $body);
        } catch (ConnectionException $e) {
            throw new UsharezIndeterminateException("Usharez POST {$path} outcome unknown: {$e->getMessage()}");
        }

        return $this->interpret($response, 'POST', $path, indeterminateOnUnknown: true);
    }

    private function interpret(
        Response $response,
        string $method,
        string $path,
        bool $indeterminateOnUnknown,
        bool $envelopeCheck = true,
    ): array
    {
        $status = $response->status();
        $body = $response->json();
        $message = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? '')
            : trim((string) $response->body());

        if ($response->failed()) {
            $text = trim("Usharez {$method} {$path}: HTTP {$status} {$message}");

            // A 5xx or timeout on a write says nothing about whether it ran. A re-POST can
            // double-charge, or come back 409 and refund while the first is still running.
            if ($indeterminateOnUnknown && ($status >= 500 || $status === 408)) {
                throw new UsharezIndeterminateException($text, null, $status);
            }

            if ($status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true)) {
                throw new SupplierApiException($text, null, $status);
            }

            // 400/402/403/404/409/422 — the supplier understood and said no.
            throw new UsharezBusinessException($text, null, $status);
        }

        if (!is_array($body)) {
            $text = "Usharez {$method} {$path}: unreadable response body";
            throw $indeterminateOnUnknown
                ? new UsharezIndeterminateException($text, null, $status)
                : new SupplierApiException($text, null, $status);
        }

        // A 200 that still reports failure in the envelope.
        if ($envelopeCheck && array_key_exists('success', $body) && $body['success'] === false) {
            throw new UsharezBusinessException(
                "Usharez {$method} {$path}: " . ($message !== '' ? $message : 'success=false'),
                null,
                $status
            );
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
