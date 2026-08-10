<?php

namespace App\Services\Usharez;

use App\Services\Suppliers\SupplierApiException;

/**
 * A write (allocate / purchase) whose outcome is UNKNOWN — the POST left the
 * process but the connection dropped or the body came back unreadable. The
 * recipient may or may not have been credited upstream, so the order must be
 * neither refunded (the customer might have received it) nor re-sent (they might
 * get it twice).
 *
 * UsharezConnector::placeOrder converts this into a PENDING result carrying a
 * synthetic external_order_id, which parks the order for manual reconciliation
 * and — because SupplierOrderFulfillment::fulfill() short-circuits on a non-null
 * external_order_id — guarantees it is never re-placed automatically.
 */
class UsharezIndeterminateException extends SupplierApiException
{
}
