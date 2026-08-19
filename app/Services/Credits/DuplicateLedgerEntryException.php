<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * Thrown when a credit transition has already been applied.
 *
 * This is the double-credit race being caught by the database rather than by
 * application logic: two concurrent admin approvals both pass their status checks,
 * both try to write the same idempotency_key, and the second one lands here.
 *
 * It is a *success* signal, not a failure — the money movement it represents has
 * already happened exactly once. Callers should roll back their own transaction and
 * carry on, not report an error to the operator.
 */
class DuplicateLedgerEntryException extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Credit transition '{$idempotencyKey}' has already been applied.");
    }
}
