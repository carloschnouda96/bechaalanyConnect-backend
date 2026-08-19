<?php

namespace App\Services\Suppliers\Connectors;

use App\BycelPurchase;
use App\Order;
use App\ProductsVariation;
use App\Services\Bycel\BycelBusinessException;
use App\Services\Bycel\BycelClaimConflictException;
use App\Services\Bycel\BycelClaimOutcome;
use App\Services\Bycel\BycelClient;
use App\Services\Bycel\BycelIndeterminateException;
use App\Services\Bycel\BycelPinResolver;
use App\Services\Bycel\BycelPurchaseLedger;
use App\Services\Suppliers\Contracts\PartialCatalogAware;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bycel / Power Group adapter (https://www.bycel.app/OutAPIV1/).
 *
 * Two families from one catalog, exactly like U-Manage telecom:
 *   voucher:{ProductId}   BuyVoucherEnabled='E'   → product_type 2, returns a PIN
 *   recharge:{ProductId}  DirectRechargeEnabled='E' → product_type 3, phone recipient
 *
 * THREE DELIBERATE DEVIATIONS FROM THE OTHER CONNECTORS
 *
 * 1. It does NOT use ExchangeRate. Bycel self-reports its FX rate PER CATALOG ROW
 *    (ValueOf1Dollar_LBP; 89,500 and 91,000 both observed in one feed), so cost is
 *    derived per row and isConfigured() requires no rate at all.
 *
 * 2. placeOrder() PRE-WRITES orders.external_order_id before the supplier POST,
 *    which every other connector leaves to SupplierOrderFulfillment. This is load
 *    bearing — do not "clean it up". fulfill() only writes that column AFTER
 *    placeOrder() returns, and a crash in between leaves it NULL, which means the
 *    order is never polled (CheckSupplierOrdersCommand filters on
 *    external_status='pending') AND the CMS Retry button will happily buy a second
 *    voucher (SupplierHealthController::retry only refuses when the column is set).
 *
 * 3. After a money-moving POST it never throws and never returns a null id.
 *    FulfillSupplierOrderJob has $tries = 3; one throw after a successful purchase
 *    would be up to three purchases.
 *
 * PIN correlation is the hard part and lives in BycelPinResolver.
 */
class BycelConnector implements SupplierConnector, PartialCatalogAware
{
    public const KEY = 'bycel';

    public const FAMILY_VOUCHER = 'voucher';
    public const FAMILY_RECHARGE = 'recharge';

    /** Recharge By Code — no recipient field; the buyer receives a PIN. */
    private const PRODUCT_TYPE_CODE = 2;
    /** Telecommunication Charge — collects recipient_phone_number. */
    private const PRODUCT_TYPE_TELECOM = 3;

    private const FLAG_ENABLED = 'E';

    /** @var string[]|null */
    private ?array $scopes = null;

    public function __construct(
        private BycelClient $client,
        private BycelPurchaseLedger $ledger,
        private BycelPinResolver $resolver,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.bycel.enabled');
    }

    /**
     * NOTE: no exchange-rate requirement, unlike usharez and umanage — Bycel ships
     * its own rate on every catalog row.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    // -------------------------------------------------------------------- catalog

    public function fetchCatalog(): array
    {
        $rows = $this->client->productList();

        $vouchers = [];
        $recharges = [];
        $vouchersOn = (bool) config('services.bycel.vouchers_enabled', true);
        $rechargeOn = (bool) config('services.bycel.recharge_enabled', true);

        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $id = $this->firstScalar($raw, ['ProductId']);
            if ($id === null) {
                continue;
            }

            $voucherOk = $this->flagEnabled($raw['BuyVoucherEnabled'] ?? null);
            $rechargeOk = $this->flagEnabled($raw['DirectRechargeEnabled'] ?? null);

            // Never sellable through any endpoint (the "Alfa Invoice" / Bills rows).
            if (!$voucherOk && !$rechargeOk) {
                continue;
            }

            $price = $this->firstNumeric($raw, ['FinalSellingPrice_LBP']);
            if ($price === null || $price <= 0) {
                // computeSellingPrice(0, profit) would publish the product for FREE.
                Log::info('Bycel row skipped: no usable FinalSellingPrice_LBP', ['product_id' => $id]);
                continue;
            }

            $rate = $this->rowRate($raw);
            if ($rate === null) {
                Log::warning('Bycel row skipped: unusable ValueOf1Dollar_LBP', [
                    'product_id' => $id,
                    'rate' => $raw['ValueOf1Dollar_LBP'] ?? null,
                ]);
                continue;
            }

            $cost = round($price / $rate, 4);
            $face = $this->firstNumeric($raw, ['FacePrice_USD']);

            // Bycel's margin is never negative, so a cost below the row's own face
            // value means the row's numbers disagree with each other.
            if ($face !== null && $face > 0 && $cost + 0.0001 < $face) {
                Log::warning('Bycel row skipped: derived cost is below its own face value', [
                    'product_id' => $id,
                    'derived_usd' => $cost,
                    'face_usd' => $face,
                    'rate' => $rate,
                ]);
                continue;
            }

            $name = trim((string) ($this->firstScalar($raw, ['ProductName']) ?? "Bycel product {$id}"));
            $provider = trim((string) ($this->firstScalar($raw, ['ProductProviderName']) ?? ''));
            $providerKey = Str::slug($provider) ?: ('p' . ($this->firstScalar($raw, ['ProductProviderId']) ?? '0'));
            $image = $this->imageUrl($raw['ProductImage'] ?? null);
            $available = $this->firstBool($raw, ['IsActive'], true);
            $externalType = trim(implode(' · ', array_filter([
                (string) ($this->firstScalar($raw, ['ProductCategoryDesc']) ?? ''),
                (string) ($this->firstScalar($raw, ['ProductServiceDesc']) ?? ''),
            ]))) ?: null;

            if ($voucherOk && $vouchersOn) {
                $vouchers[] = new SupplierProduct(
                    externalId: self::FAMILY_VOUCHER . ':' . $id,
                    name: $name,
                    categoryExternalId: 'bycel-voucher-' . $providerKey,
                    categoryName: trim(($provider !== '' ? $provider . ' ' : '') . 'Recharge Vouchers'),
                    categoryImage: null,
                    unitCost: $cost,
                    available: $available,
                    productTypeId: self::PRODUCT_TYPE_CODE,
                    qtyValues: ['min' => 1, 'max' => 1],
                    externalType: $externalType,
                    image: $image,
                );
            }

            if ($rechargeOk && $rechargeOn) {
                $recharges[] = new SupplierProduct(
                    externalId: self::FAMILY_RECHARGE . ':' . $id,
                    name: $name . ' — Direct Recharge',
                    categoryExternalId: 'bycel-recharge-' . $providerKey,
                    categoryName: trim(($provider !== '' ? $provider . ' ' : '') . 'Direct Recharge'),
                    categoryImage: null,
                    unitCost: $cost,
                    available: $available,
                    productTypeId: self::PRODUCT_TYPE_TELECOM,
                    qtyValues: ['min' => 1, 'max' => 1],
                    externalType: $externalType,
                    image: $image,
                );
            }
        }

        $scopes = [];
        if ($vouchers !== []) {
            $scopes[] = self::FAMILY_VOUCHER . ':';
        }
        if ($recharges !== []) {
            $scopes[] = self::FAMILY_RECHARGE . ':';
        }
        $this->scopes = $scopes;

        $this->warnOnAmbiguousPricing(array_merge($vouchers, $recharges));

        return array_merge($vouchers, $recharges);
    }

    public function catalogScopes(): ?array
    {
        return $this->scopes;
    }

    // ------------------------------------------------------------------- ordering

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $externalId = (string) ($variation->external_id ?: optional($variation->product)->external_id);
        [$family, $productId] = $this->splitPrefix($externalId);

        if (!in_array($family, [self::FAMILY_VOUCHER, self::FAMILY_RECHARGE], true) || $productId === '') {
            return $this->failed($order, $family, "Unrecognised Bycel external_id: {$externalId}");
        }

        // No Bycel endpoint we use takes a quantity, so >1 would under-deliver.
        if ((int) $order->quantity !== 1) {
            return $this->failed($order, $family, "Bycel products are sold one per order (order #{$order->id} requested {$order->quantity}).");
        }

        $recipient = null;
        if ($family === self::FAMILY_RECHARGE) {
            $recipient = $this->normalizePhone($order->recipient_phone_number ?: $order->recipient_user);
            if ($recipient === null) {
                return $this->failed($order, $family, "Order #{$order->id} has no usable recipient phone number.");
            }
        }

        // An intent already exists: never blindly re-POST.
        $existing = $this->ledger->findByOrderUuid($order->external_order_uuid);
        if ($existing && !$this->isReusable($existing)) {
            return $this->resolveExisting($order, $existing);
        }

        return $this->withPurchaseLock(function () use ($order, $family, $productId, $recipient) {
            return $this->purchase($order, $family, $productId, $recipient);
        });
    }

    private function purchase(Order $order, string $family, string $productId, ?string $recipient): SupplierOrderResult
    {
        // --- pre-flight, nothing spent -------------------------------------
        $snapshot = $this->productSnapshot($productId);
        if ($snapshot === null) {
            return $this->failed($order, $family, "Bycel product {$productId} is no longer in the catalog.");
        }

        $flag = $family === self::FAMILY_VOUCHER ? 'BuyVoucherEnabled' : 'DirectRechargeEnabled';
        if (!$this->flagEnabled($snapshot[$flag] ?? null) || !($snapshot['IsActive'] ?? true)) {
            return $this->failed($order, $family, "Bycel product {$productId} is not purchasable as {$family} right now.");
        }

        $cap = (int) ($snapshot['MaxSalesQtyPerDay'] ?? 0);
        if ($cap > 0 && $this->ledger->soldToday($productId) >= $cap) {
            return $this->failed($order, $family, "Bycel daily sales cap ({$cap}) reached for product {$productId}.");
        }

        $watermark = $this->currentWatermark();

        // --- committed BEFORE the POST -------------------------------------
        $purchase = $this->ledger->openIntent($order, $family, $productId, $snapshot, $watermark, $recipient);

        // --- the money-moving call -----------------------------------------
        try {
            $response = $family === self::FAMILY_VOUCHER
                ? $this->client->buyVoucher($productId, 1)
                : $this->client->directRecharge($productId, (string) $recipient);
        } catch (BycelBusinessException $e) {
            // Definitively nothing bought → refund.
            $this->ledger->markFailed($purchase, $e->getMessage());
            Log::warning('Bycel rejected order', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return new SupplierOrderResult(
                externalOrderId: $this->pendingId($family, $order),
                status: SupplierOrderResult::FAILED,
                raw: ['bycel_error' => $e->getMessage()],
            );
        } catch (BycelIndeterminateException $e) {
            // May or may not have happened: never refund, never re-send.
            $this->ledger->noteReason($purchase, 'indeterminate: ' . $e->getMessage());
            Log::critical('Bycel purchase outcome indeterminate — reconcile manually', [
                'order_id' => $order->id,
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: $this->pendingId($family, $order),
                status: SupplierOrderResult::PENDING,
                raw: ['bycel_indeterminate' => true, 'error' => $e->getMessage()],
            );
        } catch (SupplierApiException $e) {
            // Auth / IP / transport: the request was rejected at the gate, so nothing
            // was bought. Mark the intent dead so a retry may legitimately re-POST,
            // then rethrow so the job retries WITHOUT refunding the customer.
            $this->ledger->markFailed($purchase, 'not sent: ' . $e->getMessage());
            throw $e;
        }

        $this->ledger->recordResponse($purchase, (string) ($response['Result'] ?? ''));

        if (!empty($response['_bycel_partial'])) {
            Log::warning('Bycel reported partial stock on a quantity-1 order', [
                'order_id' => $order->id,
                'result' => $response['Result'] ?? null,
            ]);
        }

        // --- claim the report row ------------------------------------------
        $attempts = $family === self::FAMILY_VOUCHER
            ? (int) config('services.bycel.claim_attempts', 3)
            : 1;

        return $this->settle($order, $purchase, $this->resolver->resolve($purchase, $attempts), $response);
    }

    /**
     * Turn a resolver outcome into an order result.
     *
     * A recharge is already complete once Bycel answered OK — the claim only
     * enriches the audit trail. A voucher is NOT complete until we hold its PIN.
     */
    private function settle(Order $order, BycelPurchase $purchase, BycelClaimOutcome $outcome, array $response = []): SupplierOrderResult
    {
        $family = (string) $purchase->family;
        $isVoucher = $family === self::FAMILY_VOUCHER;
        $purchased = $purchase->response_result !== null && $purchase->response_result !== '';

        if ($outcome->is(BycelClaimOutcome::CLAIMED)) {
            $row = $outcome->row ?? [];
            $mpid = (int) ($row['MerchantPurchaseId'] ?? 0);

            try {
                $this->ledger->claim($purchase, $mpid, $row);
            } catch (BycelClaimConflictException $e) {
                // Another intent got there first — never deliver a contested code.
                $this->ledger->markAmbiguous($purchase, [$row]);

                return new SupplierOrderResult(
                    externalOrderId: $this->pendingId($family, $order),
                    status: SupplierOrderResult::PENDING,
                    raw: ['bycel_claim_conflict' => $e->getMessage()],
                );
            }

            if ($isVoucher) {
                $this->applyPin($order, $row);
            }

            return new SupplierOrderResult(
                externalOrderId: $family . ':' . $mpid,
                status: SupplierOrderResult::COMPLETED,
                raw: $response + ['bycel_merchant_purchase_id' => $mpid],
            );
        }

        if ($outcome->is(BycelClaimOutcome::AMBIGUOUS)) {
            $this->ledger->markAmbiguous($purchase, $outcome->candidates);

            return new SupplierOrderResult(
                externalOrderId: $this->pendingId($family, $order),
                status: SupplierOrderResult::PENDING,
                raw: $response + ['bycel_ambiguous' => true, 'reason' => $outcome->reason],
            );
        }

        if ($outcome->is(BycelClaimOutcome::NOT_PURCHASED)) {
            // Only reachable once a later intent closed the window: provably nothing
            // was bought, so refunding is safe.
            if (!$purchased) {
                $this->ledger->markFailed($purchase, $outcome->reason);

                return new SupplierOrderResult(
                    externalOrderId: $this->pendingId($family, $order),
                    status: SupplierOrderResult::FAILED,
                    raw: ['bycel_not_purchased' => $outcome->reason],
                );
            }

            // Bycel said OK but no row ever appeared — do not refund on a guess.
            $this->ledger->noteReason($purchase, 'OK reply but no report row: ' . $outcome->reason);
        } else {
            $this->ledger->noteReason($purchase, $outcome->reason);
        }

        // A recharge that Bycel confirmed is delivered even without an audit row.
        if (!$isVoucher && $purchased) {
            return new SupplierOrderResult(
                externalOrderId: $this->pendingId($family, $order),
                status: SupplierOrderResult::COMPLETED,
                raw: $response,
            );
        }

        return new SupplierOrderResult(
            externalOrderId: $this->pendingId($family, $order),
            status: SupplierOrderResult::PENDING,
            raw: $response + ['bycel_pending' => $outcome->reason],
        );
    }

    /**
     * Re-resolve an order whose intent already exists — the recovery path for a
     * crash mid-purchase, and what `bycel:check-orders` drives.
     */
    public function checkOrder(Order $order): SupplierOrderResult
    {
        [$family, $ref] = $this->splitPrefix((string) $order->external_order_id);
        $stored = $order->external_status ?: SupplierOrderResult::PENDING;
        $rawStored = is_array($order->external_response) ? $order->external_response : [];

        // Already claimed — nothing left to poll.
        if ($ref !== '' && ctype_digit($ref)) {
            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        $purchase = $this->ledger->findByOrderUuid($order->external_order_uuid);
        if (!$purchase) {
            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        if ($purchase->state === BycelPurchase::STATE_CLAIMED) {
            return new SupplierOrderResult(
                $family . ':' . $purchase->merchant_purchase_id,
                SupplierOrderResult::COMPLETED,
                $rawStored
            );
        }

        if (in_array($purchase->state, [BycelPurchase::STATE_ABANDONED, BycelPurchase::STATE_FAILED], true)) {
            return new SupplierOrderResult($order->external_order_id, SupplierOrderResult::FAILED, $rawStored);
        }

        return $this->settle($order, $purchase, $this->resolver->resolve($purchase, 1), $rawStored);
    }

    /** Bycel reports LBP; converted with a representative catalog rate. */
    public function balance(): ?float
    {
        try {
            $row = $this->client->balance();
        } catch (\Throwable $e) {
            return null;
        }

        $value = $row['Result'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        $rate = $this->representativeRate();

        return $rate > 0 ? round(((float) $value) / $rate, 4) : null;
    }

    // -------------------------------------------------------------------- helpers

    /**
     * Serialises every Bycel purchase. The TTL is DERIVED from the HTTP timeouts,
     * never configured independently — a TTL shorter than the worst-case request
     * would silently break the "only one purchase in flight" invariant the whole
     * claim design depends on.
     *
     * NOTE this is a cache lock, and CACHE_DRIVER is file by default: correct on a
     * single host, not across app servers. The UNIQUE index on
     * bycel_purchases.merchant_purchase_id is what keeps that survivable.
     */
    private function withPurchaseLock(callable $fn)
    {
        $get = (int) config('services.bycel.timeout_get', 30);
        $post = (int) config('services.bycel.timeout_post', 60);
        $attempts = (int) config('services.bycel.claim_attempts', 3);
        $ttl = $post + ($get * (2 + $attempts)) + 30;
        $wait = (int) config('services.bycel.lock_wait_seconds', 25);

        $lock = Cache::lock('bycel:purchase', $ttl);

        try {
            return $lock->block($wait, $fn);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Nothing was sent — retryable, and must never look like a failed order.
            throw new SupplierApiException('Bycel purchase lock is busy; another purchase is in flight.');
        }
    }

    private function currentWatermark(): int
    {
        $rows = $this->client->lastPinReport(1);
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['MerchantPurchaseId']) && is_numeric($row['MerchantPurchaseId'])) {
                return (int) $row['MerchantPurchaseId'];
            }
        }

        return 0; // fresh account with no transactions
    }

    /** @return array<string,mixed>|null */
    private function productSnapshot(string $productId): ?array
    {
        $ttl = (int) config('services.bycel.product_list_ttl', 600);

        $rows = Cache::remember('bycel:product_list', max(60, $ttl), function () {
            return $this->client->productList();
        });

        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['ProductId'] ?? '') === $productId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * orders.code must be HTML containing <li> elements: order-codes.tsx parses it
     * with DOMParser + querySelectorAll('li') and renders NOTHING when there are
     * none. SupplierOrderFulfillment saves the order after placeOrder()/checkOrder()
     * returns, so mutating it here is enough.
     */
    private function applyPin(Order $order, array $row): void
    {
        $pin = trim((string) ($row['PinCode'] ?? ''));
        if ($pin === '') {
            return;
        }

        $meta = [];
        if (!empty($row['Serial'])) {
            $meta[] = 'Serial: ' . e((string) $row['Serial']);
        }
        if (!empty($row['ProductName'])) {
            $meta[] = e(trim((string) $row['ProductName']));
        }

        $order->code = '<ul><li>' . e($pin) . '</li></ul>'
            . ($meta !== [] ? '<p>' . implode(' · ', $meta) . '</p>' : '');
    }

    private function resolveExisting(Order $order, BycelPurchase $purchase): SupplierOrderResult
    {
        if ($purchase->state === BycelPurchase::STATE_CLAIMED) {
            return new SupplierOrderResult(
                $purchase->family . ':' . $purchase->merchant_purchase_id,
                SupplierOrderResult::COMPLETED,
                []
            );
        }

        return $this->settle($order, $purchase, $this->resolver->resolve($purchase, 1));
    }

    /** A dead intent may be retried; a live or contested one may not. */
    private function isReusable(BycelPurchase $purchase): bool
    {
        return in_array($purchase->state, [BycelPurchase::STATE_FAILED, BycelPurchase::STATE_ABANDONED], true);
    }

    /** Non-null and non-numeric: keeps fulfill()'s guard armed, routes to the resolver. */
    private function pendingId(string $family, Order $order): string
    {
        return ($family !== '' ? $family : 'bycel') . ':' . ($order->external_order_uuid ?: $order->id);
    }

    private function failed(Order $order, string $family, string $message): SupplierOrderResult
    {
        Log::warning('Bycel order rejected before any spend', ['order_id' => $order->id, 'reason' => $message]);

        return new SupplierOrderResult(
            externalOrderId: $this->pendingId($family, $order),
            status: SupplierOrderResult::FAILED,
            raw: ['bycel_error' => $message],
        );
    }

    private function rowRate(array $raw): ?float
    {
        $override = config('services.bycel.rate_override');
        if (is_numeric($override) && (float) $override > 0) {
            return (float) $override;
        }

        $rate = $this->firstNumeric($raw, ['ValueOf1Dollar_LBP']);
        if ($rate === null || $rate < 1000 || $rate > 1000000) {
            return null; // absent or absurd — refuse rather than mis-price
        }

        return $rate;
    }

    private function representativeRate(): float
    {
        try {
            $rows = Cache::get('bycel:product_list') ?: $this->client->productList();
        } catch (\Throwable $e) {
            $rows = [];
        }

        foreach ($rows as $row) {
            if (is_array($row)) {
                $rate = $this->rowRate($row);
                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        $fallback = config('services.lbp_per_usd');

        return is_numeric($fallback) && (float) $fallback > 0 ? (float) $fallback : 0.0;
    }

    /**
     * Two live products sharing a price AND a face value can never be told apart in
     * last_pin_report, which turns into manual review rather than a wrong delivery.
     * Surfacing it at sync time makes the cause obvious.
     */
    private function warnOnAmbiguousPricing(array $products): void
    {
        $seen = [];
        foreach ($products as $p) {
            $key = $p->categoryExternalId . '|' . $p->unitCost;
            $seen[$key][] = $p->externalId;
        }
        foreach ($seen as $key => $ids) {
            if (count($ids) > 1) {
                Log::warning('Bycel products share a price within one category; PIN claims for them may need manual review.', [
                    'key' => $key,
                    'external_ids' => $ids,
                ]);
            }
        }
    }

    private function flagEnabled($value): bool
    {
        return is_string($value) && strcasecmp(trim($value), self::FLAG_ENABLED) === 0;
    }

    private function imageUrl($value): ?string
    {
        $url = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    /** @return array{0:string,1:string} */
    private function splitPrefix(string $value): array
    {
        $pos = strpos($value, ':');

        return $pos === false ? ['', $value] : [substr($value, 0, $pos), substr($value, $pos + 1)];
    }

    /** Lebanese 8-digit national numbers; country codes stripped. */
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
            $digits = '0' . $digits;
        }

        return strlen($digits) === 8 ? $digits : null;
    }

    private function firstScalar(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && (string) $row[$key] !== '') {
                return $row[$key];
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
