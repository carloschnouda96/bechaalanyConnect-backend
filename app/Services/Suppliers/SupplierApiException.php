<?php

namespace App\Services\Suppliers;

use RuntimeException;

/**
 * Thrown when any supplier API returns a transport error, a non-2xx status, or
 * an in-body error (numeric `code`, an `error` field, or a bare error string).
 *
 * Generalises the original YassenApiException: every supplier connector throws
 * this type, so the shared fulfillment engine can catch a single exception
 * regardless of which supplier placed the order. (YassenApiException now extends
 * this class for backward compatibility with the unchanged YassenClient.)
 */
class SupplierApiException extends RuntimeException
{
    /** Supplier-specific in-body error code, when the API provides one. */
    public ?int $apiErrorCode;

    public function __construct(string $message, ?int $apiErrorCode = null, int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
        $this->apiErrorCode = $apiErrorCode;
    }
}
