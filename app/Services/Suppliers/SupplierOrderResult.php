<?php

namespace App\Services\Suppliers;

/**
 * Normalised outcome of placing or polling a supplier order. Each connector maps
 * its own status vocabulary onto the three values below so the fulfillment engine
 * reacts uniformly:
 *   - COMPLETED / PENDING  → order stays APPROVED (PENDING is still being polled)
 *   - FAILED               → refund the customer + move the local order to REJECTED
 */
class SupplierOrderResult
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public function __construct(
        /** The supplier's order id, when one was returned. */
        public ?string $externalOrderId,
        /** One of PENDING | COMPLETED | FAILED. */
        public string $status,
        /** Raw decoded supplier payload, persisted to orders.external_response. */
        public array $raw = [],
    ) {
    }
}
