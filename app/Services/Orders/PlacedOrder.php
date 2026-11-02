<?php

namespace App\Services\Orders;

use App\Order;

/** The order OrderPlacement returned, and whether this call created it. */
final class PlacedOrder
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $created,
    ) {
    }
}
