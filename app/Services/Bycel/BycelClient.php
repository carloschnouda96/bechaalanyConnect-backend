<?php

namespace App\Services\Bycel;

use App\Services\Suppliers\SupplierApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Bycel / Power Group API
 * (https://www.bycel.app/OutAPIV1/).
 *
 * Three things make it unlike the other supplier clients:
 *
 *  1. Auth is ONE header, `InApiKey` — and, uniquely here, the CALLER'S SERVER IP
 *     must be whitelisted by Power Group. A bad key answers "Unauthorized client";
 *     a non-whitelisted IP answers "<ip> Forbidden".
 *  2. Every reply is a JSON ARRAY, e.g. `[{"Result":"OK"}]`.
 *  3. Errors are free-text strings inside `Result`, with no status envelope.
 *
 * CLASSIFICATION — the important part
 *
 *   SupplierApiException          retryable — 5xx, 401/403/408/429, auth/IP text,
 *                                 GET transport. NEVER refunds a customer.
 *   BycelBusinessException        definitive, allow-listed only → FAILED + refund
 *   BycelIndeterminateException   EVERYTHING ELSE on a write → PENDING, reconciled
 *
 * That default is deliberately inverted from the umanage/usharez clients, which
 * treat an unrecognised 4xx as a business failure. Bycel's error vocabulary is
 * undocumented free text: refunding on a string we do not understand risks
 * refunding a customer whose card WAS bought, orphaning a real voucher. The cost of
 * this choice is that some genuinely-failed orders sit PENDING until the reconciler
 * clears them, which is the cheaper mistake.
 *
 * Auth/IP errors being RETRYABLE is equally deliberate: a key rotation or a host
 * migration that changes the egress IP would otherwise mass-refund and reject every
 * pending order — the worst failure mode available.
 */
class BycelClient
{
    /** Retryable transport statuses. 403 is included because IP whitelisting uses it. */
    private const RETRYABLE_STATUSES = [401, 403, 408, 425, 429];

    /** Auth / infrastructure failures, matched against the raw body text. */
    private const AUTH_PATTERNS = [
        '/unauthorized\s+client/i',
        '/\bforbidden\b/i',
    ];

    /**
     * The ONLY strings that mean "nothing was purchased". Exact `Result` values,
     * compared case-insensitively after trimming. Grow this from live probing —
     * never widen it to a catch-all.
     */
    private const DEFINITIVE_RESULTS = ['NO', 'ERR'];

    /** Documented free-text failures that also mean nothing happened. */
    private const DEFINITIVE_PATTERNS = [
        '/operation aborted/i',
    ];

    /**
     * "We have less quantity in our stock! You could take only X out of Y."
     *
     * The most dangerous string in this API: it READS like an error, but the order
     * WAS placed for X. It must never reach the failure branch. Forcing quantity 1
     * makes it unreachable in principle; this exists so a future change to that rule
     * cannot silently turn a successful purchase into a refund.
     */
    private const PARTIAL_STOCK_PATTERN = '/less quantity in our stock/i';

    private string $baseUrl;
    private ?string $apiKey;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.bycel.base_url'), '/');
        $this->apiKey = $apiKey ?? config('services.bycel.key');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    /** True when the configured key is one of Bycel's TEST_ keys. */
    public function isTestKey(): bool
    {
        return is_string($this->apiKey) && str_starts_with($this->apiKey, 'TEST_');
    }

    // --------------------------------------------------------------------- reads

    /** GET /product_list — all active Alfa and Touch products. */
    public function productList(): array
    {
        return $this->rows($this->get('/product_list'));
    }

    /**
     * GET /last_pin_report?last=N — the ONLY place PINs appear.
     * An empty array is legitimate (a fresh account), not an error.
     */
    public function lastPinReport(int $last): array
    {
        return $this->rows($this->get('/last_pin_report', ['last' => max(1, $last)]));
    }

    /**
     * GET /balance — `[{"Result":"200000","Currency":"LBP"}]`.
     * NOTE `Result` here is the VALUE, not a status, which is why the OK convention
     * is applied only on the write path.
     */
    public function balance(): array
    {
        return $this->unwrapOne($this->get('/balance'));
    }

    /** GET /exists?mobile= — `[{"Result":"TRUE"|"FALSE","First4":"..."}]`. */
    public function exists(string $mobile): array
    {
        return $this->unwrapOne($this->get('/exists', ['mobile' => $mobile]));
    }

    // -------------------------------------------------------------------- writes

    /** POST /buy_voucher — returns no order id and no PIN. See BycelPinResolver. */
    public function buyVoucher(string $productId, int $quantity = 1): array
    {
        return $this->postCommand('/buy_voucher', [
            'productid' => $productId,
            'quantity' => (string) $quantity,
        ]);
    }

    /** POST /direct_recharge — synchronous, returns no order id. */
    public function directRecharge(string $productId, string $mobile): array
    {
        return $this->postCommand('/direct_recharge', [
            'productid' => $productId,
            'mobilenum' => $mobile,
        ]);
    }

    // ----------------------------------------------------------------- transport

    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->request()
                ->timeout($this->timeout('get', 30))
                ->retry(2, 500, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new SupplierApiException("Bycel GET {$path} failed: {$e->getMessage()}");
        }

        $this->guardTransport($response, 'GET', $path);

        $body = $response->json();
        if (!is_array($body)) {
            throw new SupplierApiException("Bycel GET {$path}: unreadable response body");
        }

        return $body;
    }

    /**
     * A money-moving POST. Never auto-retried: a repeated buy_voucher is a second
     * purchase.
     */
    private function postCommand(string $path, array $body): array
    {
        try {
            $response = $this->request()->timeout($this->timeout('post', 60))->post($path, $body);
        } catch (ConnectionException $e) {
            throw new BycelIndeterminateException("Bycel POST {$path} outcome unknown: {$e->getMessage()}");
        }

        // Auth/IP and transport failures first — these mean the request never landed.
        $this->guardTransport($response, 'POST', $path);

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new BycelIndeterminateException("Bycel POST {$path}: unreadable response body");
        }

        $row = $this->unwrapOne($decoded);
        $result = trim((string) ($row['Result'] ?? ''));

        if (strcasecmp($result, 'OK') === 0) {
            return $row;
        }

        // Partial stock is a SUCCESS for the quantity reported — check before failure.
        if (preg_match(self::PARTIAL_STOCK_PATTERN, $result)) {
            return $row + ['_bycel_partial' => true];
        }

        if ($this->isDefinitiveFailure($result)) {
            throw new BycelBusinessException("Bycel POST {$path}: {$result}", $row);
        }

        // Unrecognised: assume it MIGHT have happened.
        throw new BycelIndeterminateException(
            "Bycel POST {$path}: unrecognised reply '{$result}' — treating as indeterminate"
        );
    }

    /**
     * Auth/IP rejections arrive as body text at an unknown HTTP status, so sniff the
     * body as well as the status. Always retryable, never a customer-facing failure.
     */
    private function guardTransport(Response $response, string $method, string $path): void
    {
        $status = $response->status();
        $raw = trim((string) $response->body());

        foreach (self::AUTH_PATTERNS as $pattern) {
            if (preg_match($pattern, $raw)) {
                throw new SupplierApiException(
                    "Bycel {$method} {$path}: authorisation/IP rejection — {$this->snippet($raw)}",
                    null,
                    $status
                );
            }
        }

        if ($response->failed()) {
            if ($status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true)) {
                throw new SupplierApiException(
                    "Bycel {$method} {$path}: HTTP {$status} {$this->snippet($raw)}",
                    null,
                    $status
                );
            }

            // A non-retryable 4xx on a write is still ambiguous for Bycel.
            if ($method === 'POST') {
                throw new BycelIndeterminateException(
                    "Bycel POST {$path}: HTTP {$status} {$this->snippet($raw)}",
                    null,
                    $status
                );
            }

            throw new SupplierApiException("Bycel {$method} {$path}: HTTP {$status} {$this->snippet($raw)}", null, $status);
        }
    }

    private function isDefinitiveFailure(string $result): bool
    {
        foreach (self::DEFINITIVE_RESULTS as $known) {
            if (strcasecmp($result, $known) === 0) {
                return true;
            }
        }
        foreach (self::DEFINITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $result)) {
                return true;
            }
        }

        return false;
    }

    /** Replies are arrays; list endpoints return them as-is. */
    private function rows(array $body): array
    {
        return Arr::isList($body) ? $body : [$body];
    }

    /** Command endpoints wrap a single object in an array. */
    private function unwrapOne(array $body): array
    {
        if (Arr::isList($body)) {
            $first = $body[0] ?? [];
            return is_array($first) ? $first : [];
        }

        return $body;
    }

    private function snippet(string $raw): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 200);
    }

    private function timeout(string $which, int $default): int
    {
        $value = config("services.bycel.timeout_{$which}");

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['InApiKey' => (string) $this->apiKey])
            ->acceptJson()
            ->asJson();
    }
}
