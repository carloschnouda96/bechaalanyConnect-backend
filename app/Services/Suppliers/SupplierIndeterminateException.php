<?php

namespace App\Services\Suppliers;

/** A supplier write whose outcome is unknown: it may have been processed, so never re-send it. */
class SupplierIndeterminateException extends SupplierApiException
{
}
