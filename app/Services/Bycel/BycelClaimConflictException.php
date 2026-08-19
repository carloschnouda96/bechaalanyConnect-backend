<?php

namespace App\Services\Bycel;

use RuntimeException;

/**
 * Two intents tried to claim the same last_pin_report row, and the UNIQUE index on
 * bycel_purchases.merchant_purchase_id rejected the second.
 *
 * Reaching this means the purchase mutex failed to do its job (expired mid-flight,
 * or the cache lock is not shared — it is file-based, so it is only atomic on a
 * single host). The database catching it is the point: the failure mode degrades to
 * "one order needs manual review" instead of "two customers were handed the same
 * card".
 */
class BycelClaimConflictException extends RuntimeException
{
    public int $merchantPurchaseId;

    public function __construct(int $merchantPurchaseId)
    {
        parent::__construct("Bycel report row {$merchantPurchaseId} has already been claimed by another purchase.");
        $this->merchantPurchaseId = $merchantPurchaseId;
    }
}
