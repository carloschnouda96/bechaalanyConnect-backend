<?php

namespace App\Http\Controllers\Cms;

use App\CreditsTransfer;
use App\Http\Controllers\Controller;
use App\Order;
use Hellotreedigital\Cms\Controllers\CmsPageController;

/**
 * The vendor delete button soft-deletes a row without touching credits. On a paid order
 * that kept the customer's money and removed the order from their history; on an approved
 * top-up it hid the only record of why they hold those credits. A record tied to money
 * has to be rejected first, which moves the money through the ledger.
 *
 * `$id` may be a comma-separated list (the vendor's bulk delete). Each record is judged on
 * its own, so one refusal does not block the rest.
 */
class GuardedRecordDeleteController extends Controller
{
    public function __construct(private CmsPageController $cmsPageController)
    {
    }

    public function destroyOrders($id)
    {
        return $this->guard(
            (string) $id,
            'orders',
            'order',
            fn (array $ids) => self::ordersHoldingCredits($ids),
            'still holds the customer\'s credits, so reject it first to refund them'
        );
    }

    public function destroyTransfers($id)
    {
        return $this->guard(
            (string) $id,
            'credits-transfer',
            'top-up',
            fn (array $ids) => self::transfersHoldingCredits($ids),
            'has credited the customer, so reject it first to take the credits back'
        );
    }

    /** @return int[] Soft-deleted rows included: Deleted records asks the same question. */
    public static function ordersHoldingCredits(array $ids): array
    {
        return Order::withTrashed()->withoutGlobalScope('cms_draft_flag')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q
                ->where('statuses_id', '<>', Order::STATUS_REJECTED)
                ->orWhereNull('credits_applied_status')
                ->orWhere('credits_applied_status', '<>', Order::STATUS_REJECTED))
            ->pluck('id')
            ->all();
    }

    /** @return int[] */
    public static function transfersHoldingCredits(array $ids): array
    {
        return CreditsTransfer::withTrashed()->withoutGlobalScope('cms_draft_flag')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q
                ->where('statuses_id', CreditsTransfer::STATUS_APPROVED)
                ->orWhere('credits_applied_status', CreditsTransfer::STATUS_APPROVED))
            ->pluck('id')
            ->all();
    }

    private function guard(string $id, string $route, string $label, callable $held, string $why)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $id))));
        $refused = $held($ids);
        $allowed = array_values(array_diff($ids, $refused));

        if ($allowed) {
            $this->cmsPageController->destroy(implode(',', $allowed), $route);
        }

        // The vendor layout only prints `success`, for errors too (see CatalogDeleteController).
        $message = $refused
            ? ucfirst($label) . ' #' . implode(', #', $refused) . " not deleted: it {$why}."
                . ($allowed ? ' ' . count($allowed) . ' other record(s) deleted.' : '')
            : 'Record deleted successfully';

        return redirect(config('hellotree.cms_route_prefix') . '/' . $route)->with('success', $message);
    }
}
