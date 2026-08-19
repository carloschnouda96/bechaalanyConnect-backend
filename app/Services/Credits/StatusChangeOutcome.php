<?php

namespace App\Services\Credits;

/**
 * What a credit-affecting status change actually did.
 *
 * Returned from inside the money transaction and acted on outside it, so that
 * emails, notifications and supplier-fulfillment dispatch never run while a row
 * lock is held — and so a failing mail server can never roll back a credit movement.
 */
class StatusChangeOutcome
{
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly float $delta,
        public readonly bool $shouldFulfill = false,
    ) {
    }

    public function moneyMoved(): bool
    {
        return $this->delta !== 0.0;
    }
}
