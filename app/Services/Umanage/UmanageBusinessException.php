<?php

namespace App\Services\Umanage;

use App\Services\Suppliers\SupplierApiException;

/**
 * A DEFINITIVE U-Manage rejection: the request was understood and refused (a
 * non-retryable 4xx, or an HTTP 200 carrying `success:false`). Retrying gets the
 * same answer, so UmanageConnector::placeOrder turns it into
 * SupplierOrderResult::FAILED — the engine then refunds the customer's credits
 * and moves the order to REJECTED.
 *
 * Unlike the usharez equivalent this carries the decoded response body, because
 * a failed telecom order still returns a real `order.order_id` (and a
 * `refunded: true` flag) that is worth recording on the local order.
 */
class UmanageBusinessException extends SupplierApiException
{
    public array $body;

    public function __construct(string $message, array $body = [], ?int $apiErrorCode = null, int $httpStatus = 0)
    {
        parent::__construct($message, $apiErrorCode, $httpStatus);
        $this->body = $body;
    }
}
