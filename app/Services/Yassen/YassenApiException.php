<?php

namespace App\Services\Yassen;

use App\Services\Suppliers\SupplierApiException;

/**
 * Thrown when the Yassen-Card API returns a transport error, a non-2xx status,
 * or an in-body error code (e.g. 121 = bad/expired api-token).
 *
 * Extends the shared SupplierApiException so the generic fulfillment engine can
 * catch a single supplier exception type regardless of which supplier failed.
 * The constructor lives on the parent; YassenClient throws this subclass
 * unchanged for backward compatibility.
 */
class YassenApiException extends SupplierApiException
{
}
