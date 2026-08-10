<?php

namespace App\Services\Umanage;

use App\Services\Suppliers\SupplierApiException;

/**
 * A U-Manage write whose outcome is UNKNOWN — the POST left the process but the
 * connection dropped or the body was unreadable. The wallet may or may not have
 * been charged, so the order must be neither refunded nor re-sent.
 *
 * UmanageConnector::placeOrder converts this into a PENDING result with a
 * synthetic external_order_id, which parks the order for manual reconciliation
 * and stops SupplierOrderFulfillment::fulfill() from ever re-placing it.
 */
class UmanageIndeterminateException extends SupplierApiException
{
}
