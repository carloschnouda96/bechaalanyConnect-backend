<?php

namespace App\Services\Bycel;

use App\BycelPurchase;
use App\Order;
use App\Services\Suppliers\SupplierApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Durable record of every intent to buy from Bycel, and the sole writer of claims.
 *
 * The entire crash-safety argument for this connector rests on openIntent()
 * committing BEFORE any money-moving POST leaves the process. See the
 * create_bycel_purchases migration for the reasoning.
 */
class BycelPurchaseLedger
{
    public function findByOrderUuid(?string $uuid): ?BycelPurchase
    {
        if (!$uuid) {
            return null;
        }

        return BycelPurchase::where('order_uuid', $uuid)->first();
    }

    public function findOpenOlderThan(int $minutes, int $limit = 50)
    {
        return BycelPurchase::where('state', BycelPurchase::STATE_SENT)
            ->where('created_at', '<=', Carbon::now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Record the intent AND pre-write the order, in one committed transaction,
     * before the POST.
     *
     * Why the order pre-write lives here rather than in SupplierOrderFulfillment:
     * fulfill() only writes external_order_id AFTER placeOrder() returns
     * (SupplierOrderFulfillment.php:91). If the process dies between a successful
     * buy_voucher and that write, the order is left with external_order_id NULL —
     * and then
     *   - CheckSupplierOrdersCommand never polls it (it filters on
     *     external_status = 'pending'), so it is never reconciled; and
     *   - SupplierHealthController::retry() happily re-dispatches it, because it
     *     only refuses when external_order_id is filled — buying a SECOND voucher.
     * Writing both columns up front closes that window.
     */
    public function openIntent(
        Order $order,
        string $family,
        string $productId,
        array $snapshot,
        int $watermark,
        ?string $recipient = null
    ): BycelPurchase {
        // If a caller ever wraps fulfillment in a transaction, the intent row would
        // not be committed before the POST and this whole protection would silently
        // evaporate. Fail loudly instead, the way CreditLedger::record() does.
        if (DB::transactionLevel() !== 0) {
            throw new SupplierApiException(
                'Bycel purchases must not run inside a database transaction: the intent row '
                . 'must be committed before the supplier POST.'
            );
        }

        $uuid = (string) $order->external_order_uuid;
        if ($uuid === '') {
            throw new SupplierApiException('Bycel purchase requires orders.external_order_uuid to be set.');
        }

        return DB::transaction(function () use ($order, $family, $productId, $snapshot, $watermark, $recipient, $uuid) {
            $purchase = BycelPurchase::create([
                'order_id' => $order->id,
                'order_uuid' => $uuid,
                'family' => $family,
                'product_id' => $productId,
                'recipient' => $recipient,
                'watermark_id' => $watermark,
                'state' => BycelPurchase::STATE_SENT,
                'snapshot' => $snapshot,
                'request_at' => now(),
            ]);

            Order::withoutGlobalScope('cms_draft_flag')
                ->where('id', $order->id)
                ->update([
                    'external_source' => 'bycel',
                    'external_order_id' => $family . ':' . $uuid,
                    'external_status' => \App\Services\Suppliers\SupplierOrderResult::PENDING,
                ]);

            // Keep the in-memory model consistent with what we just persisted.
            $order->external_source = 'bycel';
            $order->external_order_id = $family . ':' . $uuid;
            $order->external_status = \App\Services\Suppliers\SupplierOrderResult::PENDING;

            return $purchase;
        });
    }

    public function recordResponse(BycelPurchase $purchase, string $result): void
    {
        $purchase->response_result = mb_substr($result, 0, 255);
        $purchase->response_at = now();
        $purchase->save();
    }

    /**
     * Attach a last_pin_report row to this intent.
     *
     * The UNIQUE index on merchant_purchase_id is the real guard: if the mutex
     * failed and two intents raced for the same row, the database rejects the
     * second. Degrading to "one order needs review" beats handing two customers the
     * same card.
     */
    public function claim(BycelPurchase $purchase, int $merchantPurchaseId, array $row): void
    {
        $pin = (string) ($row['PinCode'] ?? '');

        $payload = [
            'merchant_purchase_id' => $merchantPurchaseId,
            'state' => BycelPurchase::STATE_CLAIMED,
            'claimed_at' => now(),
            'report_row' => $this->redact($row),
            'serial' => isset($row['Serial']) ? mb_substr((string) $row['Serial'], 0, 64) : null,
            'pin_last4' => $pin !== '' ? mb_substr($pin, -4) : null,
            'pin_hash' => $pin !== '' ? hash('sha256', $pin) : null,
            'cost_lbp' => isset($row['PriceAfterDiscount_LBP']) && is_numeric($row['PriceAfterDiscount_LBP'])
                ? (float) $row['PriceAfterDiscount_LBP'] : null,
            'discount_percentage' => isset($row['DiscountPercentage']) && is_numeric($row['DiscountPercentage'])
                ? (float) $row['DiscountPercentage'] : null,
            'resolver_reason' => null,
        ];

        try {
            $affected = BycelPurchase::where('id', $purchase->id)
                ->whereNull('merchant_purchase_id')
                ->update($payload);
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation (the UNIQUE index fired).
            if ((string) $e->getCode() === '23000') {
                throw new BycelClaimConflictException($merchantPurchaseId);
            }
            throw $e;
        }

        if ($affected === 0) {
            throw new BycelClaimConflictException($merchantPurchaseId);
        }

        $purchase->forceFill($payload);
    }

    public function markFailed(BycelPurchase $purchase, string $reason): void
    {
        $this->markState($purchase, BycelPurchase::STATE_FAILED, $reason);
    }

    /**
     * Park the intent for human review, keeping the candidate rows (PINs redacted)
     * so the CMS can show an admin what it could not decide between.
     */
    public function markAmbiguous(BycelPurchase $purchase, array $candidates): void
    {
        $rows = [];
        $ids = [];
        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids[] = $row['MerchantPurchaseId'] ?? null;
            $redacted = $this->redact($row);
            // Enough to recognise the card without exposing it.
            $redacted['PinLast4'] = isset($row['PinCode']) ? mb_substr((string) $row['PinCode'], -4) : null;
            $rows[] = $redacted;
        }

        $purchase->report_row = ['candidates' => $rows];
        $purchase->save();

        $this->markState(
            $purchase,
            BycelPurchase::STATE_AMBIGUOUS,
            'candidates: ' . implode(',', array_filter($ids))
        );
    }

    /** The candidate rows recorded when the claim was refused. */
    public function candidatesFor(BycelPurchase $purchase): array
    {
        $stored = is_array($purchase->report_row) ? $purchase->report_row : [];

        return is_array($stored['candidates'] ?? null) ? $stored['candidates'] : [];
    }

    public function markAbandoned(BycelPurchase $purchase, string $reason = 'abandoned by admin'): void
    {
        $this->markState($purchase, BycelPurchase::STATE_ABANDONED, $reason);
    }

    public function noteReason(BycelPurchase $purchase, string $reason): void
    {
        $purchase->resolver_reason = mb_substr($reason, 0, 191);
        $purchase->save();
    }

    /**
     * The watermark of the next intent recorded after this one. It closes the
     * search window: our purchase, if any, sits in (watermark_id, thisValue].
     * Null means no later intent exists yet, so the window is still open-ended.
     */
    public function nextWatermarkAfter(BycelPurchase $purchase): ?int
    {
        $next = BycelPurchase::where('id', '>', $purchase->id)
            ->orderBy('id')
            ->value('watermark_id');

        return $next === null ? null : (int) $next;
    }

    /** @return int[] report rows already claimed by some intent. */
    public function claimedIds(int $above = 0): array
    {
        return BycelPurchase::whereNotNull('merchant_purchase_id')
            ->where('merchant_purchase_id', '>', $above)
            ->pluck('merchant_purchase_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * How many units of this product WE have bought today. Bycel's
     * MaxSalesQtyPerDay is shared with the merchant's own app, so this can
     * undercount — an upstream rejection is still handled cleanly as a refund.
     */
    public function soldToday(string $productId): int
    {
        return BycelPurchase::where('product_id', $productId)
            ->whereIn('state', [BycelPurchase::STATE_SENT, BycelPurchase::STATE_CLAIMED, BycelPurchase::STATE_AMBIGUOUS])
            ->where('created_at', '>=', Carbon::today())
            ->count();
    }

    private function markState(BycelPurchase $purchase, string $state, string $reason): void
    {
        $purchase->state = $state;
        $purchase->resolver_reason = mb_substr($reason, 0, 191);
        $purchase->save();
    }

    /** Never persist a second plaintext copy of a bearer instrument. */
    private function redact(array $row): array
    {
        if (isset($row['PinCode'])) {
            $row['PinCode'] = '***redacted***';
        }

        return $row;
    }
}
