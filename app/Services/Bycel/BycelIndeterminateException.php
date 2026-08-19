<?php

namespace App\Services\Bycel;

use App\Services\Suppliers\SupplierApiException;

/**
 * A Bycel write whose outcome is UNKNOWN — a dropped POST, an unreadable body, or
 * any error string not on the recognised failure allow-list.
 *
 * The order must be neither refunded (a card may have been bought and is sitting in
 * last_pin_report) nor re-sent (that would buy a second one). BycelConnector maps
 * this to a PENDING result whose external_order_id is already set, so
 * `bycel:check-orders` and `bycel:reconcile` can resolve it later against the
 * persisted intent row.
 */
class BycelIndeterminateException extends SupplierApiException
{
}
