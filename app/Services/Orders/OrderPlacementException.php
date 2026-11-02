<?php

namespace App\Services\Orders;

use RuntimeException;

/**
 * An order that was refused before any credit moved. Carries the machine-readable
 * `code` and HTTP status the storefront has always received, plus any extra
 * figures (min_quantity, balance/required/missing …) the caller can act on.
 */
class OrderPlacementException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
