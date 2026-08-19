<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * A status change would push a user's balance negative.
 *
 * Raised by the CMS status-change path when re-debiting an order (rejected →
 * pending/approved) costs more than the user currently holds. The previous code had
 * no such check on that branch — it simply subtracted, so re-approving a rejected
 * order after the user had spent the refund left them with a negative balance and
 * no indication anything was wrong.
 *
 * `revertTo` is the status the ledger is still settled at, so the caller can put the
 * row back where it was.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $revertTo,
        public readonly float $balance,
        public readonly float $required
    ) {
        parent::__construct(sprintf(
            'This change needs %s credits but the user only has %s. The status has been left unchanged.',
            number_format($required, 2),
            number_format($balance, 2)
        ));
    }
}
