<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\HasCreditLine;
use App\Services\Suppliers\Contracts\PartialCatalogAware;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\ExchangeRate;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Services\Usharez\UsharezBusinessException;
use App\Services\Usharez\UsharezClient;
use App\Services\Usharez\UsharezIndeterminateException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * usharez adapter (https://bechaalanyconnect.usharez.com, Bearer-header auth).
 *
 * Unlike the other three suppliers, usharez exposes TWO unrelated product
 * families behind one account, each with its own catalog + purchase endpoint:
 *
 *   quota  GET /api/quota/admin/stock  →  POST /api/v2/quota/admin/allocate (async)
 *   gift   GET /api/alfa-gifts         →  POST /api/alfa-gifts/purchase   (sync)
 *
 * The family is encoded into external_id as "{family}:{ref}" ("quota:16.5",
 * "gift:Alfa $0.5") and surfaces as two supplier categories, so an admin can
 * enable each independently on the CMS "Supplier Categories" page. A failure in
 * one family never aborts the other, and catalogScopes() stops the sync engine
 * from deactivating a family it could not reach (see PartialCatalogAware).
 *
 * Quota prices arrive in LBP; the storefront is USD-only, so unitCost is divided
 * by the LBP→USD rate (Fixed Settings, falling back to config/env).
 *
 * Quota allocation is async (API v2): the POST answers 202 with a jobId, which is
 * stored as external_order_id "quota:{jobId}" and polled by checkOrder until the
 * job is terminal. On success the job reports the admin line the customer must
 * text to 1323, written to orders.code. Gift purchases are still synchronous, so
 * placeOrder returns them terminal and checkOrder echoes their stored status.
 */
class UsharezConnector implements SupplierConnector, PartialCatalogAware, HasCreditLine
{
    public const KEY = 'usharez';

    public const FAMILY_QUOTA = 'quota';
    public const FAMILY_GIFT = 'gift';

    public const CATEGORY_QUOTA = 'usharez-quota';
    public const CATEGORY_GIFT = 'usharez-alfa-gifts';

    /**
     * Marker on the <p> written after the admin number in orders.code. The
     * storefront (order-codes.tsx) swaps it for a localized "text this number to
     * 1323" instruction — the backend does not know the customer's language.
     */
    public const NOTE_TEXT_1323 = 'usharez-text-1323';

    /** A v2 jobId is opaque; anything outside this is never put in a URL. */
    private const JOB_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Both families collect a phone number → product_type 3 (Telecommunication Charge). */
    private const PRODUCT_TYPE_TELECOM = 3;

    /** @var string[]|null Prefixes vouched for by the last fetchCatalog() call. */
    private ?array $scopes = null;

    /** Memoised LBP→USD rate so one sync doesn't re-query fixed_settings per row. */
    private ?float $resolvedRate = null;

    /**
     * $lbpPerUsdOverride exists so unit tests can pin the rate without touching
     * the database; the container auto-wires it as null in production.
     */
    public function __construct(
        private UsharezClient $client,
        private ?float $lbpPerUsdOverride = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.usharez.enabled');
    }

    /** A zero/negative divisor would produce INF prices, so treat it as unconfigured. */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->lbpPerUsd() > 0;
    }

    // -------------------------------------------------------------------- catalog

    public function fetchCatalog(): array
    {
        if ($this->lbpPerUsd() <= 0) {
            throw new SupplierApiException(
                'Usharez LBP→USD rate is not configured (set it in Fixed Settings or USHAREZ_LBP_PER_USD).'
            );
        }

        $out = [];
        $scopes = [];
        $attempted = 0;
        $failed = 0;

        if ((bool) config('services.usharez.quota_enabled', true)) {
            $attempted++;
            try {
                $rows = $this->fetchQuotaFamily();
                if ($rows === []) {
                    // Empty stock is indistinguishable from a silent upstream failure;
                    // don't vouch for the family, so nothing gets deactivated.
                    Log::warning('Usharez quota stock returned no rows — skipping quota deactivation this run.');
                } else {
                    $scopes[] = self::FAMILY_QUOTA . ':';
                }
                $out = array_merge($out, $rows);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Usharez quota catalog fetch failed', ['error' => $e->getMessage()]);
            }
        }

        if ((bool) config('services.usharez.gifts_enabled', true)) {
            $attempted++;
            try {
                $rows = $this->fetchGiftFamily();
                if ($rows !== []) {
                    $scopes[] = self::FAMILY_GIFT . ':';
                }
                $out = array_merge($out, $rows);
            } catch (\Throwable $e) {
                $failed++;
                // Expected until usharez grants this token the Alfa Gifts permission
                // (the live account currently gets HTTP 403 on /api/alfa-gifts).
                Log::warning('Usharez alfa-gifts catalog fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // Every family failing means the supplier is down, not that it withdrew its
        // catalog — surface it as an error instead of reporting an empty catalog.
        if ($attempted > 0 && $failed === $attempted) {
            throw new SupplierApiException('Usharez catalog fetch failed for every enabled family.');
        }

        $this->scopes = $scopes;

        return $out;
    }

    public function catalogScopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * {"success":true,"data":[{"quota_size":"67 GB","price":2220000},…]}
     *
     * `quota_size` is a display string but allocate wants a number, and 16.5 / 5.5
     * are fractional — so the numeric value is parsed out and re-encoded into
     * external_id while the original label stays as the product name.
     */
    private function fetchQuotaFamily(): array
    {
        $rows = $this->extractList($this->client->quotaStock());
        $out = [];

        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $label = trim((string) $this->firstString($raw, ['quota_size', 'quotaSize', 'size', 'name']));
            $size = $this->parseQuotaSize($label);
            $price = $this->firstNumeric($raw, ['price', 'amount', 'cost']);

            if ($size === null || $size <= 0 || $price === null) {
                Log::warning('Usharez quota row skipped (unparseable)', ['row' => $raw]);
                continue;
            }

            $out[] = new SupplierProduct(
                externalId: self::FAMILY_QUOTA . ':' . $this->formatQuotaSize($size),
                name: $label,
                categoryExternalId: self::CATEGORY_QUOTA,
                categoryName: 'Internet Quota Bundles',
                categoryImage: null,
                unitCost: $this->toUsd((float) $price, 'lbp'),
                // Presence in the stock list implies availability (no flag returned).
                available: true,
                productTypeId: self::PRODUCT_TYPE_TELECOM,
                // allocate() has no qty parameter — one allocation per order.
                qtyValues: ['min' => 1, 'max' => 1],
                externalType: self::FAMILY_QUOTA,
            );
        }

        return $out;
    }

    /**
     * Shape UNVERIFIED — the live token gets 403 on GET /api/alfa-gifts — so every
     * lookup tolerates alternate key spellings and an unparseable row is skipped
     * with a log rather than throwing. Tighten this once a real payload is seen.
     */
    private function fetchGiftFamily(): array
    {
        $rows = $this->extractList($this->client->alfaGifts());
        $out = [];
        $maxQty = max(1, (int) config('services.usharez.gift_max_qty', 10));
        $currency = (string) config('services.usharez.gifts_currency', 'usd');

        foreach ($rows as $raw) {
            if (is_string($raw)) {
                $raw = ['service' => $raw]; // a bare list of service names
            }
            if (!is_array($raw)) {
                continue;
            }

            $service = trim((string) $this->firstString($raw, ['service', 'name', 'title', 'label', 'gift']));
            if ($service === '') {
                continue;
            }

            $price = $this->firstNumeric($raw, ['price', 'amount', 'cost', 'rate', 'value']);
            if ($price === null) {
                Log::warning('Usharez alfa-gift row has no recognisable price', ['row' => $raw]);
                continue;
            }

            $out[] = new SupplierProduct(
                externalId: self::FAMILY_GIFT . ':' . $service,
                name: $service,
                categoryExternalId: self::CATEGORY_GIFT,
                categoryName: 'Alfa Gifts',
                categoryImage: null,
                unitCost: $this->toUsd((float) $price, $currency),
                available: $this->firstBool($raw, ['available', 'in_stock', 'active'], true),
                productTypeId: self::PRODUCT_TYPE_TELECOM,
                qtyValues: ['min' => 1, 'max' => $maxQty],
                externalType: self::FAMILY_GIFT,
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------- ordering

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $externalId = (string) ($variation->external_id ?: optional($variation->product)->external_id);
        [$family, $ref] = $this->parseExternalId($externalId);

        try {
            return match ($family) {
                self::FAMILY_QUOTA => $this->placeQuotaOrder($order, $ref),
                self::FAMILY_GIFT => $this->placeGiftOrder($order, $ref),
                default => throw new UsharezBusinessException("Unrecognised usharez external_id: {$externalId}"),
            };
        } catch (UsharezIndeterminateException $e) {
            // Never refund and never re-place — park it PENDING for a human.
            Log::critical('Usharez order outcome indeterminate — reconcile manually', [
                'order_id' => $order->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: $this->syntheticOrderId($order, []),
                status: SupplierOrderResult::PENDING,
                raw: ['usharez_indeterminate' => true, 'error' => $e->getMessage()],
            );
        } catch (UsharezBusinessException $e) {
            // Definitive rejection → the engine refunds and marks the order REJECTED.
            Log::warning('Usharez rejected order', [
                'order_id' => $order->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: $this->syntheticOrderId($order, []),
                status: SupplierOrderResult::FAILED,
                raw: ['usharez_error' => $e->getMessage(), 'http_status' => $e->getCode()],
            );
        }

        // A plain SupplierApiException (5xx / 401) deliberately propagates out of
        // SupplierOrderFulfillment::fulfill() so FulfillSupplierOrderJob retries.
    }

    private function placeQuotaOrder(Order $order, string $ref): SupplierOrderResult
    {
        // allocate() takes no qty, and product_type 3 renders a quantity stepper the
        // storefront does not pin, so this guard is the real enforcement.
        if ((int) $order->quantity !== 1) {
            throw new UsharezBusinessException(
                "Quota allocation supports quantity 1 only (order #{$order->id} requested {$order->quantity})."
            );
        }

        $phone = $this->normalizePhone($order->recipient_phone_number ?: $order->recipient_user);
        if ($phone === null) {
            throw new UsharezBusinessException("Order #{$order->id} has no usable recipient phone number.");
        }

        $size = (float) $ref;
        if ($size <= 0) {
            throw new UsharezBusinessException("Order #{$order->id} has an invalid quota size: {$ref}");
        }

        // 11.0 → 11 (int, as the docs show); 16.5 stays a float.
        $quotaSize = $size == (int) $size ? (int) $size : $size;

        if (!(bool) config('services.usharez.quota_async', true)) {
            // v1 rollback path — synchronous, so the answer is already terminal.
            $response = $this->client->allocateQuota($phone, $quotaSize);

            return new SupplierOrderResult(
                externalOrderId: $this->syntheticOrderId($order, $response),
                status: SupplierOrderResult::COMPLETED,
                raw: $response,
            );
        }

        $response = $this->client->allocateQuotaAsync($phone, $quotaSize);
        $jobId = $response['jobId'] ?? $response['job_id'] ?? null;

        if (!is_scalar($jobId) || !preg_match(self::JOB_ID_PATTERN, (string) $jobId)) {
            // Accepted but untrackable: a job may exist, so it must be neither
            // refunded nor re-sent — the indeterminate path parks it for a human.
            throw new UsharezIndeterminateException(
                "Usharez accepted allocate for order #{$order->id} but returned no usable jobId."
            );
        }

        // Normally "queued"; a job that already finished is honoured as such.
        $status = $this->mapJobStatus($response['status'] ?? null, null, SupplierOrderResult::PENDING);
        if ($status === SupplierOrderResult::COMPLETED) {
            $this->applyAdminNumber($order, is_array($response['data'] ?? null) ? $response['data'] : []);
        }

        // The family prefix is what tells checkOrder this id is pollable.
        // external_order_id is the only durable carrier: refreshStatus() overwrites
        // external_response wholesale on every poll.
        return new SupplierOrderResult(
            externalOrderId: self::FAMILY_QUOTA . ':' . $jobId,
            status: $status,
            raw: $response,
        );
    }

    private function placeGiftOrder(Order $order, string $ref): SupplierOrderResult
    {
        $phone = $this->normalizePhone($order->recipient_phone_number ?: $order->recipient_user);
        if ($phone === null) {
            throw new UsharezBusinessException("Order #{$order->id} has no usable recipient phone number.");
        }

        $response = $this->client->purchaseGift($ref, $phone, max(1, (int) $order->quantity));

        return new SupplierOrderResult(
            externalOrderId: $this->syntheticOrderId($order, $response),
            status: SupplierOrderResult::COMPLETED,
            raw: $response,
        );
    }

    /**
     * Only a v2 quota job ("quota:{jobId}") is pollable. Everything else — gift
     * orders, v1-era quota orders, and synthetic "usharez-{uuid}" ids parked
     * PENDING by an indeterminate POST — is echoed with NO HTTP call and no status
     * change, so a parked order stays visible until an admin resolves it.
     */
    public function checkOrder(Order $order): SupplierOrderResult
    {
        $stored = $order->external_status ?: SupplierOrderResult::COMPLETED;
        $rawStored = is_array($order->external_response) ? $order->external_response : [];
        [$family, $jobId] = $this->parseExternalId((string) $order->external_order_id);

        if ($family !== self::FAMILY_QUOTA || !preg_match(self::JOB_ID_PATTERN, $jobId)) {
            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        try {
            $response = $this->client->quotaJobStatus($jobId);
        } catch (UsharezBusinessException $e) {
            // A 404/403 on a lookup is not "the recharge failed" — refunding on it
            // would hand back money for a quota that may well have been delivered.
            Log::warning('Usharez job lookup rejected — keeping stored status', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }
        // SupplierApiException (5xx/401/429) propagates; the check command logs it
        // per order and the next run polls again.

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $refunded = array_key_exists('refunded', $data)
            ? filter_var($data['refunded'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $jobStatus = strtolower(trim((string) ($response['status'] ?? '')));
        $status = $this->mapJobStatus($jobStatus, $refunded, $stored);

        if ($status === SupplierOrderResult::COMPLETED) {
            $this->applyAdminNumber($order, $data);
        }

        if ($status === SupplierOrderResult::FAILED && $refunded !== true) {
            // The customer is refunded either way (nothing was delivered); this is
            // our money held at usharez, to settle with them.
            Log::warning('Usharez job failed without a usharez refund — reconcile funds with usharez', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'job_status' => $jobStatus,
                'error' => $data['error'] ?? null,
            ]);
        }

        if ($jobStatus === 'stopped' && $status === SupplierOrderResult::PENDING) {
            if (empty($rawStored['usharez_needs_review'])) {
                // Logged once, on the transition — the order is re-polled every 5 min.
                Log::critical('Usharez job stopped without a refund — resolve manually (reject in the CMS to refund)', [
                    'order_id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'error' => $data['error'] ?? null,
                ]);
            }
            $response['usharez_needs_review'] = true;
        }

        return new SupplierOrderResult(
            externalOrderId: $order->external_order_id,
            status: $status,
            raw: $response,
        );
    }

    /**
     * queued/processing/retrying → pending; succeeded → completed;
     * rejected/failed → failed. `stopped` is undocumented: it refunds the customer
     * only when usharez says it refunded us, otherwise it stays pending for a
     * human. Anything unrecognised keeps $fallback rather than guessing.
     */
    private function mapJobStatus(?string $status, ?bool $refunded, string $fallback): string
    {
        return match (strtolower(trim((string) $status))) {
            'queued', 'processing', 'retrying' => SupplierOrderResult::PENDING,
            'succeeded' => SupplierOrderResult::COMPLETED,
            'rejected', 'failed' => SupplierOrderResult::FAILED,
            'stopped' => $refunded === true ? SupplierOrderResult::FAILED : SupplierOrderResult::PENDING,
            default => $fallback,
        };
    }

    /**
     * The admin line the customer texts to 1323 to receive the quota.
     *
     * orders.code MUST be HTML containing <li> elements: the storefront parses it
     * with DOMParser + querySelectorAll('li') and renders NOTHING when there are
     * none (see order-codes.tsx). SupplierOrderFulfillment saves the order after
     * checkOrder() returns, so setting it here is enough.
     */
    private function applyAdminNumber(Order $order, array $data): void
    {
        $admin = is_array($data['allocated_admin'] ?? null) ? $data['allocated_admin'] : [];
        $number = $admin['phone_number'] ?? null;

        if (!is_scalar($number) || trim((string) $number) === '') {
            return;
        }

        $order->code = '<ul><li>' . e(trim((string) $number)) . '</li></ul>'
            . '<p data-note="' . self::NOTE_TEXT_1323 . '"></p>';
    }

    /** The balance endpoint reports LBP; normalised to USD for comparability. */
    public function balance(): ?float
    {
        try {
            $response = $this->client->balance();
        } catch (\Throwable $e) {
            return null;
        }

        $value = $response['balance'] ?? $response['data']['balance'] ?? null;

        return is_numeric($value) && $this->lbpPerUsd() > 0
            ? $this->toUsd((float) $value, 'lbp')
            : null;
    }

    /** usharez may let the account run negative up to `debt_allowance` (LBP). */
    public function creditLine(): ?float
    {
        try {
            $response = $this->client->balance();
        } catch (\Throwable $e) {
            return null;
        }

        $value = $response['debt_allowance'] ?? $response['data']['debt_allowance'] ?? null;

        return is_numeric($value) && $this->lbpPerUsd() > 0
            ? $this->toUsd((float) $value, 'lbp')
            : null;
    }

    // -------------------------------------------------------------------- helpers

    /** @return array{0:string,1:string} [family, ref] */
    private function parseExternalId(string $externalId): array
    {
        $pos = strpos($externalId, ':');

        return $pos === false
            ? ['', $externalId]
            : [substr($externalId, 0, $pos), substr($externalId, $pos + 1)];
    }

    /** "16.5 GB" → 16.5, "67 GB" → 67.0, unparseable → null. */
    private function parseQuotaSize(string $label): ?float
    {
        return preg_match('/(\d+(?:\.\d+)?)/', $label, $m) ? (float) $m[1] : null;
    }

    /** 11.0 → "11", 16.5 → "16.5" — deterministic so external_id stays stable. */
    private function formatQuotaSize(float $size): string
    {
        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.');
    }

    /**
     * Lebanese mobile numbers are 8 national digits where the leading 0 belongs to
     * the operator prefix (03…); international form drops it (+961 3 123456). That
     * makes the quota doc's "12345678" and the gift doc's "03123456" the same
     * shape. `services.usharez.phone_format` is the escape hatch if live testing
     * proves one endpoint wants the zero stripped.
     */
    private function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        foreach (['00961', '961'] as $cc) {
            if (str_starts_with($digits, $cc) && strlen($digits) > strlen($cc) + 5) {
                $digits = substr($digits, strlen($cc));
                break;
            }
        }

        if (strlen($digits) === 7) {
            $digits = '0' . $digits; // +961 3 123456 → 03123456
        }
        if (strlen($digits) < 7 || strlen($digits) > 9) {
            return null;
        }

        return config('services.usharez.phone_format', 'national8') === 'strip_leading_zero'
            ? ltrim($digits, '0')
            : $digits;
    }

    /**
     * usharez returns no order id, but SupplierOrderFulfillment::fulfill() uses a
     * non-null external_order_id as its ONLY re-entry guard — leaving it null would
     * let a job retry re-POST and double-charge. Prefer any id-ish field the API
     * happens to return, else derive a stable one from the local dedupe uuid
     * (which fulfill() persists BEFORE calling placeOrder, so it survives retries).
     */
    private function syntheticOrderId(Order $order, array $response): string
    {
        $bags = [$response, is_array($response['data'] ?? null) ? $response['data'] : []];

        foreach (['order_id', 'orderId', 'id', 'transaction_id', 'transactionId', 'reference', 'ref'] as $key) {
            foreach ($bags as $bag) {
                if (isset($bag[$key]) && is_scalar($bag[$key]) && (string) $bag[$key] !== '') {
                    return (string) $bag[$key];
                }
            }
        }

        return 'usharez-' . ($order->external_order_uuid ?: $order->id);
    }

    /**
     * LBP→USD divisor, memoised per connector instance. The usharez-specific
     * Fixed Settings column stays the highest-priority override; below it sits
     * the shared platform rate every LBP supplier reads (see ExchangeRate).
     */
    private function lbpPerUsd(): float
    {
        if ($this->lbpPerUsdOverride !== null) {
            return $this->lbpPerUsdOverride;
        }
        if ($this->resolvedRate !== null) {
            return $this->resolvedRate;
        }

        return $this->resolvedRate = ExchangeRate::lbpPerUsd(
            'usharez_lbp_per_usd',
            'services.usharez.lbp_per_usd'
        );
    }

    private function toUsd(float $amount, string $currency): float
    {
        if (strtolower($currency) === 'usd') {
            return round($amount, 4);
        }

        $rate = $this->lbpPerUsd();

        return $rate > 0 ? round($amount / $rate, 4) : 0.0;
    }

    /** List endpoints may wrap rows under data/services/items; flatten to a list. */
    private function extractList($response): array
    {
        if (!is_array($response)) {
            return [];
        }
        foreach (['data', 'services', 'gifts', 'items', 'result'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }

        return Arr::isList($response) ? $response : [$response];
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && (string) $row[$key] !== '') {
                return (string) $row[$key];
            }
        }

        return null;
    }

    private function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }

    private function firstBool(array $row, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return filter_var($row[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
            }
        }

        return $default;
    }
}
