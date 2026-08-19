<?php

namespace Tests\Unit\Support;

use App\BycelPurchase;
use App\Order;
use App\Services\Bycel\BycelClaimConflictException;
use App\Services\Bycel\BycelPurchaseLedger;
use App\Services\Suppliers\SupplierOrderResult;

/**
 * In-memory stand-in for BycelPurchaseLedger.
 *
 * The real ledger is the only DB-touching part of the Bycel purchase path, and an
 * `orders` row needs a whole product → variation → order chain to satisfy its
 * foreign keys. Substituting it keeps every judgement call in BycelPinResolver and
 * BycelConnector — the logic that decides whether a customer is handed a card —
 * unit-testable without a database.
 */
class FakeBycelLedger extends BycelPurchaseLedger
{
    public ?BycelPurchase $purchase = null;
    public ?int $nextWatermark = null;
    public array $claimed = [];
    public int $soldToday = 0;
    public int $openIntentCalls = 0;
    /** @var array<int,int> merchant_purchase_id => times claimed */
    public array $claimAttempts = [];
    public bool $failNextClaimWithConflict = false;

    public function __construct(?BycelPurchase $existing = null)
    {
        $this->purchase = $existing;
    }

    public function findByOrderUuid(?string $uuid): ?BycelPurchase
    {
        return $this->purchase && $this->purchase->order_uuid === $uuid ? $this->purchase : null;
    }

    public function openIntent(
        Order $order,
        string $family,
        string $productId,
        array $snapshot,
        int $watermark,
        ?string $recipient = null
    ): BycelPurchase {
        $this->openIntentCalls++;

        $purchase = new BycelPurchase([
            'order_id' => $order->id,
            'order_uuid' => (string) $order->external_order_uuid,
            'family' => $family,
            'product_id' => $productId,
            'recipient' => $recipient,
            'watermark_id' => $watermark,
            'state' => BycelPurchase::STATE_SENT,
            'snapshot' => $snapshot,
        ]);
        $purchase->id = 1;

        // Mirror the real ledger's order pre-write, which is what keeps
        // SupplierOrderFulfillment's re-entry guard armed across a crash.
        $order->external_source = 'bycel';
        $order->external_order_id = $family . ':' . $order->external_order_uuid;
        $order->external_status = SupplierOrderResult::PENDING;

        return $this->purchase = $purchase;
    }

    public function recordResponse(BycelPurchase $purchase, string $result): void
    {
        $purchase->response_result = $result;
    }

    public function claim(BycelPurchase $purchase, int $merchantPurchaseId, array $row): void
    {
        $this->claimAttempts[$merchantPurchaseId] = ($this->claimAttempts[$merchantPurchaseId] ?? 0) + 1;

        if ($this->failNextClaimWithConflict) {
            throw new BycelClaimConflictException($merchantPurchaseId);
        }

        $purchase->merchant_purchase_id = $merchantPurchaseId;
        $purchase->state = BycelPurchase::STATE_CLAIMED;
        $this->claimed[] = $merchantPurchaseId;
    }

    public function markFailed(BycelPurchase $purchase, string $reason): void
    {
        $purchase->state = BycelPurchase::STATE_FAILED;
        $purchase->resolver_reason = $reason;
    }

    public function markAmbiguous(BycelPurchase $purchase, array $candidates): void
    {
        $purchase->state = BycelPurchase::STATE_AMBIGUOUS;
    }

    public function markAbandoned(BycelPurchase $purchase, string $reason = 'abandoned by admin'): void
    {
        $purchase->state = BycelPurchase::STATE_ABANDONED;
    }

    public function noteReason(BycelPurchase $purchase, string $reason): void
    {
        $purchase->resolver_reason = $reason;
    }

    public function nextWatermarkAfter(BycelPurchase $purchase): ?int
    {
        return $this->nextWatermark;
    }

    public function claimedIds(int $above = 0): array
    {
        return $this->claimed;
    }

    public function soldToday(string $productId): int
    {
        return $this->soldToday;
    }
}
