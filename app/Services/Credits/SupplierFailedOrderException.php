<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * Refuses to take an order out of Rejected once its supplier reported it failed. The
 * customer would be charged again, but the supplier order is finished and is neither
 * re-sent nor polled, so nothing would ever be delivered.
 */
class SupplierFailedOrderException extends RuntimeException
{
    public function __construct(public readonly int $revertTo)
    {
        parent::__construct('The supplier already failed this order and it was refunded. Ask the customer to place a new order.');
    }
}
