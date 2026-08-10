<?php

namespace App\Services\Usharez;

use App\Services\Suppliers\SupplierApiException;

/**
 * A DEFINITIVE supplier rejection: the request reached usharez, was understood,
 * and was refused (a 4xx, or a 200 carrying `success:false`). Retrying would get
 * the same answer, so UsharezConnector::placeOrder turns this into
 * SupplierOrderResult::FAILED — which makes SupplierOrderFulfillment refund the
 * customer's credits and move the order to REJECTED.
 *
 * Contrast with a plain SupplierApiException (5xx / 401 / transport), which
 * propagates so FulfillSupplierOrderJob retries instead of refunding.
 */
class UsharezBusinessException extends SupplierApiException
{
}
