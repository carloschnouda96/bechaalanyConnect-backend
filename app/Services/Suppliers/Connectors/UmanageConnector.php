<?php

namespace App\Services\Suppliers\Connectors;

use App\Order;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\PartialCatalogAware;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\ExchangeRate;
use App\Services\Suppliers\SupplierApiException;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\Services\Umanage\UmanageBusinessException;
use App\Services\Umanage\UmanageClient;
use App\Services\Umanage\UmanageIndeterminateException;
use Illuminate\Support\Facades\Log;

/**
 * U-Manage adapter (https://api.umanageapp.uk/api/v1/external).
 *
 * The most multi-headed supplier here: FIVE sellable families across three
 * order endpoints, all scoped by a store id the client discovers at runtime.
 *
 *   bundle            /stock            → POST /orders          (async)
 *   sms-credit        /sms-products     → POST /sms-orders      (async)
 *   sms-gift          /sms-products     → POST /sms-orders      (async)
 *   telecom-voucher   /telecom-products → POST /telecom-orders  (sync, returns a code)
 *   telecom-recharge  /telecom-products → POST /telecom-orders  (sync)
 *
 * The family is encoded into external_id as `{family}:{ref}` and each family
 * gets its own supplier category, so they are enabled independently in the CMS.
 *
 * Because bundles and SMS are genuinely asynchronous, checkOrder() is real here
 * (unlike usharez) — and it receives only the Order, so the family is ALSO
 * encoded into `external_order_id` as `{family}:{supplier_order_id}`. That
 * column is the only durable carrier available: refreshStatus() overwrites
 * external_response wholesale, and external_response is not a registered CMS
 * field so a CMS schema-save can drop it.
 *
 * Prices arrive in LBP; unitCost is divided by the shared platform rate.
 */
class UmanageConnector implements SupplierConnector, PartialCatalogAware
{
    public const KEY = 'umanage';

    public const FAMILY_BUNDLE = 'bundle';
    public const FAMILY_SMS_CREDIT = 'sms-credit';
    public const FAMILY_SMS_GIFT = 'sms-gift';
    public const FAMILY_TELECOM_VOUCHER = 'telecom-voucher';
    public const FAMILY_TELECOM_RECHARGE = 'telecom-recharge';

    public const CATEGORY_BUNDLE = 'umanage-bundles';
    public const CATEGORY_SMS_CREDIT = 'umanage-sms-credit';
    public const CATEGORY_SMS_GIFT = 'umanage-sms-gifts';
    public const CATEGORY_TELECOM_VOUCHER = 'umanage-telecom-vouchers';
    public const CATEGORY_TELECOM_RECHARGE = 'umanage-telecom-recharge';

    /** Delivered to a phone number → Telecommunication Charge. */
    private const PRODUCT_TYPE_TELECOM = 3;
    /** Voucher: no recipient, the buyer receives a redeemable code. */
    private const PRODUCT_TYPE_CODE = 2;

    /** @var string[]|null Prefixes vouched for by the last fetchCatalog(). */
    private ?array $scopes = null;

    private ?float $resolvedRate = null;

    public function __construct(
        private UmanageClient $client,
        private ?float $lbpPerUsdOverride = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.umanage.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->lbpPerUsd() > 0;
    }

    // -------------------------------------------------------------------- catalog

    public function fetchCatalog(): array
    {
        if ($this->lbpPerUsd() <= 0) {
            throw new SupplierApiException(
                'U-Manage LBP→USD rate is not configured (set it in Fixed Settings or LBP_PER_USD).'
            );
        }

        $out = [];
        $scopes = [];
        $attempted = 0;
        $failed = 0;

        // --- bundles -----------------------------------------------------------
        if ((bool) config('services.umanage.bundles_enabled', true)) {
            $attempted++;
            try {
                $rows = $this->fetchBundleFamily();
                if ($rows === []) {
                    Log::warning('U-Manage bundle stock returned no rows — skipping bundle deactivation this run.');
                } else {
                    $scopes[] = self::FAMILY_BUNDLE . ':';
                }
                $out = array_merge($out, $rows);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('U-Manage bundle catalog fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // --- SMS (one call feeds both the credit and gift families) -------------
        if ((bool) config('services.umanage.sms_enabled', true)) {
            $attempted++;
            try {
                [$credits, $gifts] = $this->fetchSmsFamilies();
                if ($credits !== []) {
                    $scopes[] = self::FAMILY_SMS_CREDIT . ':';
                }
                if ($gifts !== []) {
                    $scopes[] = self::FAMILY_SMS_GIFT . ':';
                }
                $out = array_merge($out, $credits, $gifts);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('U-Manage sms-products fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // --- telecom (one call feeds both voucher and recharge families) --------
        if ((bool) config('services.umanage.telecom_enabled', true)) {
            $attempted++;
            try {
                [$vouchers, $recharges] = $this->fetchTelecomFamilies();
                if ($vouchers !== []) {
                    $scopes[] = self::FAMILY_TELECOM_VOUCHER . ':';
                }
                if ($recharges !== []) {
                    $scopes[] = self::FAMILY_TELECOM_RECHARGE . ':';
                }
                $out = array_merge($out, $vouchers, $recharges);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('U-Manage telecom-products fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // Every group failing means the supplier (or the store lookup) is down —
        // surface it rather than reporting an empty catalog, which deactivates.
        if ($attempted > 0 && $failed === $attempted) {
            throw new SupplierApiException('U-Manage catalog fetch failed for every enabled family.');
        }

        $this->scopes = $scopes;

        return $out;
    }

    public function catalogScopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * /stock rows: {bundle_id, bundle_size, bundle_type, bundle_days, price_lbp,
     * is_available}. /stock is used rather than /bundles because the docs state
     * it reflects actual inventory while /bundles lists configured products only.
     */
    private function fetchBundleFamily(): array
    {
        $rows = $this->extractList($this->client->stock(), ['bundles', 'data', 'stock']);
        $out = [];

        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $id = $this->firstScalar($raw, ['bundle_id', 'id']);
            $price = $this->firstNumeric($raw, ['price_lbp', 'price']);
            if ($id === null || $price === null) {
                Log::warning('U-Manage bundle row skipped (unparseable)', ['row' => $raw]);
                continue;
            }

            $size = $this->firstScalar($raw, ['bundle_size', 'size']);
            $days = $this->firstNumeric($raw, ['bundle_days', 'days']);
            $type = $this->firstScalar($raw, ['bundle_type', 'type']);

            $name = $size !== null ? "{$size} GB Data Bundle" : "Data Bundle {$id}";
            if ($days !== null && $days > 0) {
                $name .= ' — ' . (int) $days . ' days';
            }

            $out[] = new SupplierProduct(
                externalId: self::FAMILY_BUNDLE . ':' . $id,
                name: $name,
                categoryExternalId: self::CATEGORY_BUNDLE,
                categoryName: 'Data Bundles',
                categoryImage: null,
                unitCost: $this->toUsd((float) $price),
                available: $this->firstBool($raw, ['is_available', 'available'], true),
                productTypeId: self::PRODUCT_TYPE_TELECOM,
                qtyValues: ['min' => 1, 'max' => 1],
                externalType: $type !== null ? (string) $type : self::FAMILY_BUNDLE,
            );
        }

        return $out;
    }

    /**
     * /sms-products carries two lists. `credits[]` groups by carrier and holds a
     * `pricing[]` table of {amount_usd, price_lbp} — one product per priced row.
     * min/max/increment imply more amounts than are priced, but synthesising them
     * would invent prices, so only what the API actually prices is imported.
     *
     * @return array{0: SupplierProduct[], 1: SupplierProduct[]} [credits, gifts]
     */
    private function fetchSmsFamilies(): array
    {
        $body = $this->client->smsProducts();

        if (array_key_exists('has_sms_service', $body) && !$body['has_sms_service']) {
            Log::warning('U-Manage store has no SMS service — skipping SMS families.');
            return [[], []];
        }

        $this->checkRateAgainstFaceValue($body);

        $credits = [];
        foreach ($this->asList($body['credits'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $carrier = strtolower((string) ($this->firstScalar($group, ['carrier_type', 'carrier']) ?? 'alfa'));

            foreach ($this->asList($group['pricing'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $amount = $this->firstNumeric($row, ['amount_usd', 'amount']);
                $price = $this->firstNumeric($row, ['price_lbp', 'price']);
                if ($amount === null || $price === null) {
                    continue;
                }

                $amountRef = $this->trimNumber($amount);

                $credits[] = new SupplierProduct(
                    externalId: self::FAMILY_SMS_CREDIT . ':' . $carrier . ':' . $amountRef,
                    name: '$' . $amountRef . ' ' . ucfirst($carrier) . ' Credit',
                    categoryExternalId: self::CATEGORY_SMS_CREDIT,
                    categoryName: 'Credit Top-Ups',
                    categoryImage: null,
                    unitCost: $this->toUsd((float) $price),
                    available: true,
                    productTypeId: self::PRODUCT_TYPE_TELECOM,
                    qtyValues: ['min' => 1, 'max' => 1],
                    externalType: self::FAMILY_SMS_CREDIT,
                );
            }
        }

        $gifts = [];
        foreach ($this->asList($body['gifts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $this->firstScalar($row, ['product_code', 'code']);
            $price = $this->firstNumeric($row, ['price_lbp', 'price']);
            if ($code === null || $price === null) {
                Log::warning('U-Manage sms gift row skipped (unparseable)', ['row' => $row]);
                continue;
            }
            $carrier = strtolower((string) ($this->firstScalar($row, ['carrier_type', 'carrier']) ?? 'alfa'));
            $name = (string) ($this->firstScalar($row, ['name', 'title']) ?? $code);
            $validity = $this->firstNumeric($row, ['validity_days']);
            if ($validity !== null && $validity > 0) {
                $name .= ' — ' . (int) $validity . ' days';
            }

            $gifts[] = new SupplierProduct(
                externalId: self::FAMILY_SMS_GIFT . ':' . $carrier . ':' . $code,
                name: $name,
                categoryExternalId: self::CATEGORY_SMS_GIFT,
                categoryName: 'Gift Bundles',
                categoryImage: null,
                unitCost: $this->toUsd((float) $price),
                available: true,
                productTypeId: self::PRODUCT_TYPE_TELECOM,
                qtyValues: ['min' => 1, 'max' => 1],
                externalType: self::FAMILY_SMS_GIFT,
            );
        }

        return [$credits, $gifts];
    }

    /**
     * A telecom product can be sellable as a voucher AND as a direct recharge, so
     * it may yield two catalog rows in two different categories — they need
     * different product types (a voucher has no recipient).
     *
     * Excluded: `supports_plans` products (their plan_id is looked up per
     * recipient at purchase time, which the one-variation storefront can't
     * express) and products declaring `required_fields` (extra inputs with no UI).
     *
     * @return array{0: SupplierProduct[], 1: SupplierProduct[]} [vouchers, recharges]
     */
    private function fetchTelecomFamilies(): array
    {
        $rows = $this->extractList($this->client->telecomProducts(), ['products', 'data']);
        $vouchers = [];
        $recharges = [];

        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $id = $this->firstScalar($raw, ['product_id', 'id']);
            $price = $this->firstNumeric($raw, ['price_lbp', 'price']);
            if ($id === null || $price === null) {
                Log::warning('U-Manage telecom row skipped (unparseable)', ['row' => $raw]);
                continue;
            }

            if (!empty($raw['supports_plans'])) {
                Log::info('U-Manage telecom product skipped: plan products are not importable', ['product_id' => $id]);
                continue;
            }
            if (!empty($raw['required_fields'])) {
                Log::info('U-Manage telecom product skipped: declares required_fields', ['product_id' => $id]);
                continue;
            }

            $name = (string) ($this->firstScalar($raw, ['name', 'title']) ?? "Telecom product {$id}");
            $category = (string) ($this->firstScalar($raw, ['category', 'brand']) ?? 'Telecom');
            $image = $this->firstScalar($raw, ['image_url', 'image']);
            $cost = $this->toUsd((float) $price);
            $productType = strtolower((string) ($this->firstScalar($raw, ['product_type']) ?? ''));

            if ($productType === 'voucher') {
                $vouchers[] = new SupplierProduct(
                    externalId: self::FAMILY_TELECOM_VOUCHER . ':' . $id,
                    name: $name,
                    categoryExternalId: self::CATEGORY_TELECOM_VOUCHER,
                    categoryName: 'Recharge Vouchers',
                    categoryImage: $image !== null ? (string) $image : null,
                    unitCost: $cost,
                    available: true,
                    // No recipient — the buyer receives a redeemable code.
                    productTypeId: self::PRODUCT_TYPE_CODE,
                    qtyValues: ['min' => 1, 'max' => 1],
                    externalType: $category,
                );
            }

            if (!empty($raw['supports_direct_recharge'])) {
                $recharges[] = new SupplierProduct(
                    externalId: self::FAMILY_TELECOM_RECHARGE . ':' . $id,
                    name: $name . ' — Direct Recharge',
                    categoryExternalId: self::CATEGORY_TELECOM_RECHARGE,
                    categoryName: 'Direct Recharge',
                    categoryImage: $image !== null ? (string) $image : null,
                    unitCost: $cost,
                    available: true,
                    productTypeId: self::PRODUCT_TYPE_TELECOM,
                    qtyValues: ['min' => 1, 'max' => 1],
                    externalType: $category,
                );
            }
        }

        return [$vouchers, $recharges];
    }

    // ------------------------------------------------------------------- ordering

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        $externalId = (string) ($variation->external_id ?: optional($variation->product)->external_id);
        [$family, $ref] = $this->splitPrefix($externalId);

        try {
            // No U-Manage endpoint takes a quantity, so >1 would silently
            // under-deliver. Reject it before spending anything.
            if ((int) $order->quantity !== 1) {
                throw new UmanageBusinessException(
                    "U-Manage products are sold one per order (order #{$order->id} requested {$order->quantity})."
                );
            }

            return match ($family) {
                self::FAMILY_BUNDLE => $this->placeBundleOrder($order, $ref),
                self::FAMILY_SMS_CREDIT => $this->placeSmsOrder($order, $ref, 'credit'),
                self::FAMILY_SMS_GIFT => $this->placeSmsOrder($order, $ref, 'gift'),
                self::FAMILY_TELECOM_VOUCHER => $this->placeTelecomOrder($order, $ref, 'voucher'),
                self::FAMILY_TELECOM_RECHARGE => $this->placeTelecomOrder($order, $ref, 'recharge'),
                default => throw new UmanageBusinessException("Unrecognised U-Manage external_id: {$externalId}"),
            };
        } catch (UmanageIndeterminateException $e) {
            Log::critical('U-Manage order outcome indeterminate — reconcile manually', [
                'order_id' => $order->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult(
                externalOrderId: $this->syntheticOrderId($order, $family),
                status: SupplierOrderResult::PENDING,
                raw: ['umanage_indeterminate' => true, 'error' => $e->getMessage()],
            );
        } catch (UmanageBusinessException $e) {
            Log::warning('U-Manage rejected order', [
                'order_id' => $order->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            // A failed telecom order still carries a real order id worth keeping.
            $supplierId = $this->extractOrderId($e->body);

            return new SupplierOrderResult(
                externalOrderId: $supplierId !== null
                    ? $family . ':' . $supplierId
                    : $this->syntheticOrderId($order, $family),
                status: SupplierOrderResult::FAILED,
                raw: ['umanage_error' => $e->getMessage(), 'http_status' => $e->getCode()] + $e->body,
            );
        }

        // A plain SupplierApiException (5xx / 401 / 429) propagates so the job retries.
    }

    private function placeBundleOrder(Order $order, string $ref): SupplierOrderResult
    {
        $phone = $this->requirePhone($order);

        $response = $this->client->createOrder($ref, $phone, $order->external_order_uuid);
        $row = is_array($response['order'] ?? null) ? $response['order'] : [];

        return new SupplierOrderResult(
            externalOrderId: $this->qualifiedOrderId($order, self::FAMILY_BUNDLE, $row),
            status: $this->mapStatus($row['status'] ?? null),
            raw: $response,
        );
    }

    private function placeSmsOrder(Order $order, string $ref, string $productType): SupplierOrderResult
    {
        $phone = $this->requirePhone($order);
        // ref is "{carrier}:{code}" — carrier is optional for resilience.
        [$carrier, $code] = $this->splitPrefix($ref);
        if ($code === '') {
            $code = $carrier;
            $carrier = 'alfa';
        }

        $response = $this->client->createSmsOrder(
            $productType,
            $code,
            $phone,
            $carrier ?: 'alfa',
            $order->external_order_uuid
        );
        $row = is_array($response['order'] ?? null) ? $response['order'] : [];

        $family = $productType === 'gift' ? self::FAMILY_SMS_GIFT : self::FAMILY_SMS_CREDIT;

        return new SupplierOrderResult(
            externalOrderId: $this->qualifiedOrderId($order, $family, $row),
            status: $this->mapStatus($row['status'] ?? null),
            raw: $response,
        );
    }

    private function placeTelecomOrder(Order $order, string $ref, string $mode): SupplierOrderResult
    {
        $payload = [
            'product_id' => is_numeric($ref) ? (int) $ref : $ref,
            'purchase_mode' => $mode,
            'app_user_reference' => $order->external_order_uuid,
        ];

        if ($mode === 'recharge') {
            $payload['recipient_phone'] = $this->requirePhone($order);
        }

        $response = $this->client->createTelecomOrder($payload);
        $row = is_array($response['order'] ?? null) ? $response['order'] : [];

        $family = $mode === 'voucher' ? self::FAMILY_TELECOM_VOUCHER : self::FAMILY_TELECOM_RECHARGE;
        $status = $this->mapStatus($row['status'] ?? null);

        if ($status === SupplierOrderResult::COMPLETED) {
            $this->applyVoucher($order, $row);
        }

        return new SupplierOrderResult(
            externalOrderId: $this->qualifiedOrderId($order, $family, $row),
            status: $status,
            raw: $response,
        );
    }

    /**
     * Bundles and SMS are asynchronous, so this is a real poll. The family is
     * recovered from the `{family}:{id}` prefix on external_order_id, which is
     * the only column that survives — refreshStatus() overwrites external_response.
     */
    public function checkOrder(Order $order): SupplierOrderResult
    {
        [$family, $ref] = $this->splitPrefix((string) $order->external_order_id);
        $stored = $order->external_status ?: SupplierOrderResult::PENDING;
        $rawStored = is_array($order->external_response) ? $order->external_response : [];

        // A synthetic id (indeterminate placement) is not pollable.
        if ($ref === '' || !ctype_digit($ref)) {
            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        try {
            $response = match ($family) {
                self::FAMILY_BUNDLE => $this->client->order($ref),
                self::FAMILY_SMS_CREDIT, self::FAMILY_SMS_GIFT => $this->client->smsOrder($ref),
                self::FAMILY_TELECOM_VOUCHER, self::FAMILY_TELECOM_RECHARGE => $this->client->telecomOrder($ref),
                default => null,
            };
        } catch (UmanageBusinessException $e) {
            // A 404/403 on a lookup must not be read as "the order failed", or a
            // transient permission slip would mass-refund customers.
            Log::warning('U-Manage order lookup rejected — keeping stored status', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'error' => $e->getMessage(),
            ]);

            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        if ($response === null) {
            return new SupplierOrderResult($order->external_order_id, $stored, $rawStored);
        }

        $row = is_array($response['order'] ?? null) ? $response['order'] : [];
        $status = $this->mapStatus($row['status'] ?? null, $stored);

        if ($status === SupplierOrderResult::COMPLETED) {
            $this->applyVoucher($order, $row);
        }

        return new SupplierOrderResult(
            externalOrderId: $order->external_order_id,
            status: $status,
            raw: $response,
        );
    }

    /** GET /wallet reports LBP; normalised to USD for comparability. */
    public function balance(): ?float
    {
        try {
            $response = $this->client->wallet();
        } catch (\Throwable $e) {
            return null;
        }

        $value = $response['wallet']['balance_lbp']
            ?? $response['balance_lbp']
            ?? $response['wallet']['balance']
            ?? null;

        return is_numeric($value) && $this->lbpPerUsd() > 0 ? $this->toUsd((float) $value) : null;
    }

    // -------------------------------------------------------------------- helpers

    /**
     * Sanity-check the LBP→USD divisor against the supplier's own numbers.
     *
     * SMS rows carry a face value (`amount_usd`/`price_usd` — what the recipient
     * receives) alongside `price_lbp` (what we pay, INCLUDING U-Manage's margin).
     * Those are not two views of one price, so their ratio is NOT an exchange
     * rate: live data spans 92,000–150,000 LBP per face dollar.
     *
     * What it does give is a hard upper bound. Since margin is never negative,
     * `price_lbp / face_usd >= true_rate` for every row — so if our configured
     * divisor exceeds the smallest such ratio, we are deriving a USD cost below
     * the face value being delivered, i.e. selling at a guaranteed loss. That is
     * always a misconfiguration and is worth shouting about. Diagnostic only.
     */
    private function checkRateAgainstFaceValue(array $body): void
    {
        $samples = [];

        foreach ($this->asList($body['credits'] ?? []) as $group) {
            foreach ($this->asList(is_array($group) ? ($group['pricing'] ?? []) : []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $usd = $this->firstNumeric($row, ['amount_usd', 'amount']);
                $lbp = $this->firstNumeric($row, ['price_lbp', 'price']);
                if ($usd !== null && $usd > 0 && $lbp !== null && $lbp > 0) {
                    $samples[] = $lbp / $usd;
                }
            }
        }
        foreach ($this->asList($body['gifts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $usd = $this->firstNumeric($row, ['price_usd']);
            $lbp = $this->firstNumeric($row, ['price_lbp']);
            if ($usd !== null && $usd > 0 && $lbp !== null && $lbp > 0) {
                $samples[] = $lbp / $usd;
            }
        }

        if ($samples === []) {
            return;
        }

        $ceiling = min($samples);
        $configured = $this->lbpPerUsd();

        $context = [
            'configured_lbp_per_usd' => $configured,
            'max_safe_lbp_per_usd' => round($ceiling, 2),
            'samples' => count($samples),
        ];

        if ($configured > $ceiling) {
            Log::warning(
                'U-Manage LBP→USD rate is too high — derived costs fall below the face value delivered. '
                . 'Lower it in Fixed Settings (lbp_per_usd).',
                $context
            );
        } else {
            Log::info('U-Manage rate sanity check passed', $context);
        }
    }

    /**
     * Persist a delivered voucher code onto the order so the customer sees it.
     *
     * orders.code MUST be HTML containing <li> elements: the storefront parses it
     * with DOMParser + querySelectorAll('li') and renders NOTHING when there are
     * none (see order-codes.tsx). SupplierOrderFulfillment saves the order after
     * placeOrder()/checkOrder() returns, so setting it here is enough.
     */
    private function applyVoucher(Order $order, array $row): void
    {
        $voucher = is_array($row['voucher'] ?? null) ? $row['voucher'] : [];
        $code = $this->firstScalar($voucher, ['code', 'voucher_code', 'pin']);

        if ($code === null || (string) $code === '') {
            return;
        }

        $items = '<li>' . e((string) $code) . '</li>';

        // Serial / expiry are context, not codes — keep them out of the <li> list
        // the UI renders as copyable codes, but still show them to the customer.
        $meta = [];
        foreach (['serial' => 'Serial', 'expiry_date' => 'Expires'] as $key => $label) {
            if (!empty($voucher[$key]) && is_scalar($voucher[$key])) {
                $meta[] = $label . ': ' . e((string) $voucher[$key]);
            }
        }

        $order->code = '<ul>' . $items . '</ul>'
            . ($meta !== [] ? '<p>' . implode(' · ', $meta) . '</p>' : '');
    }

    /** processing/pending → pending; completed → completed; failed → failed. */
    private function mapStatus(?string $status, ?string $fallback = null): string
    {
        if ($status === null || $status === '') {
            return $fallback ?: SupplierOrderResult::PENDING;
        }

        return match (strtolower(trim($status))) {
            'completed', 'complete', 'success', 'delivered' => SupplierOrderResult::COMPLETED,
            'failed', 'fail', 'cancelled', 'canceled', 'rejected' => SupplierOrderResult::FAILED,
            default => SupplierOrderResult::PENDING, // processing, pending, queued…
        };
    }

    /** @return array{0:string,1:string} [prefix, rest] split on the first colon. */
    private function splitPrefix(string $value): array
    {
        $pos = strpos($value, ':');

        return $pos === false ? ['', $value] : [substr($value, 0, $pos), substr($value, $pos + 1)];
    }

    private function requirePhone(Order $order): string
    {
        $phone = $this->normalizePhone($order->recipient_phone_number ?: $order->recipient_user);
        if ($phone === null) {
            throw new UmanageBusinessException("Order #{$order->id} has no usable recipient phone number.");
        }

        return $phone;
    }

    /**
     * U-Manage documents 8-digit Lebanese numbers, so the leading operator zero
     * is stripped (03123456 → 3123456 would be wrong; 8 digits INCLUDES it —
     * "70123456" in their examples). Country codes are removed, nothing else.
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

        return strlen($digits) === 8 ? $digits : null;
    }

    /** `{family}:{supplier_id}` so checkOrder can route; synthetic when absent. */
    private function qualifiedOrderId(Order $order, string $family, array $row): string
    {
        $id = $this->extractOrderId(['order' => $row]);

        return $id !== null ? $family . ':' . $id : $this->syntheticOrderId($order, $family);
    }

    private function extractOrderId(array $body): ?string
    {
        $bags = [
            is_array($body['order'] ?? null) ? $body['order'] : [],
            $body,
        ];

        foreach (['order_id', 'orderId', 'id', 'new_order_id'] as $key) {
            foreach ($bags as $bag) {
                if (isset($bag[$key]) && is_scalar($bag[$key]) && (string) $bag[$key] !== '') {
                    return (string) $bag[$key];
                }
            }
        }

        return null;
    }

    /**
     * SupplierOrderFulfillment::fulfill() treats a non-null external_order_id as
     * its only re-entry guard, so a null would let a retry re-POST and
     * double-charge. Still family-prefixed so checkOrder routes consistently.
     */
    private function syntheticOrderId(Order $order, string $family): string
    {
        return ($family !== '' ? $family : 'unknown') . ':umanage-' . ($order->external_order_uuid ?: $order->id);
    }

    private function lbpPerUsd(): float
    {
        if ($this->lbpPerUsdOverride !== null) {
            return $this->lbpPerUsdOverride;
        }
        if ($this->resolvedRate !== null) {
            return $this->resolvedRate;
        }

        return $this->resolvedRate = ExchangeRate::lbpPerUsd(null, 'services.umanage.lbp_per_usd');
    }

    private function toUsd(float $lbp): float
    {
        $rate = $this->lbpPerUsd();

        return $rate > 0 ? round($lbp / $rate, 4) : 0.0;
    }

    /** Unwrap a list from the first matching envelope key. */
    private function extractList($response, array $keys): array
    {
        if (!is_array($response)) {
            return [];
        }
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }

        return [];
    }

    private function asList($value): array
    {
        return is_array($value) ? array_values($value) : [];
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

    /** 5.0 → "5", 5.5 → "5.5" — stable refs and clean product names. */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
