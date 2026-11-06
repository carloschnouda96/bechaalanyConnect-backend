<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * A status change the money rules refuse, raised under the record's row lock. The caller
 * puts the status back to $revertTo, because the ledger is still settled there.
 */
class StatusChangeRefusedException extends RuntimeException
{
    public function __construct(public readonly int $revertTo, string $message)
    {
        parent::__construct($message);
    }
}
