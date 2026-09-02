<?php

namespace App\Http\Controllers\Cms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Jobs\FulfillSupplierOrderJob;
use App\Models\User;
use App\Order;
use App\CreditLedgerEntry;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Credits\StatusChangeOutcome;
use App\Services\Suppliers\SupplierOrderFulfillment;

class OrdersController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;

    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    /**
     * Total profit across approved orders, or NULL when it cannot be computed.
     *
     * Returning null rather than 0.0 on failure is the point. This used to be
     * `catch (\Throwable) { return 0.0; }` with no logging, so any breakage showed
     * the admin a confident "$0.00" indistinguishable from a genuinely zero-profit
     * day. The view renders "unavailable" for null.
     */
    public static function calculateTotalProfit(): ?float
    {
        try {
            $profit = Order::where('statuses_id', Order::STATUS_APPROVED)
                // INNER JOIN: orders whose variation was deleted drop out entirely.
                // orders.product_variation_id still has no foreign key.
                ->join('products_variations', 'orders.product_variation_id', '=', 'products_variations.id')
                ->selectRaw('COALESCE(SUM(orders.total_price - (COALESCE(products_variations.cost_price,0) * COALESCE(orders.quantity,1))),0) as total_profit')
                ->value('total_profit');

            return (float) $profit;
        } catch (\Throwable $e) {
            Log::error('Total profit calculation failed', ['exception' => $e]);

            return null;
        }
    }

    public function update(Request $request, $id)
    {
        // Validated before the vendor write so an out-of-range status is rejected
        // rather than persisted. The money decision below does NOT read this value —
        // it re-reads the row under a lock — but a bad value should still 422 early.
        $request->validate([
            'statuses_id' => ['required', Rule::in([
                Order::STATUS_APPROVED,
                Order::STATUS_REJECTED,
                Order::STATUS_PENDING,
            ])],
        ]);

        $redirect = $this->cmsPageController->update($request, $id, 'orders', 'App\Order', self::class);

        try {
            $outcome = $this->applyStatusChange((int) $id);
        } catch (InsufficientCreditsException $e) {
            // Undo the vendor's status write: the ledger is still settled at the old
            // status, so leaving the new one would desynchronise them.
            Order::withoutGlobalScope('cms_draft_flag')
                ->where('id', $id)
                ->update(['statuses_id' => $e->revertTo]);

            // 422 renders as a bullet list in the CMS toast (see main.js:414).
            throw ValidationException::withMessages(['statuses_id' => [$e->getMessage()]]);
        }

        // Side effects run OUTSIDE the transaction, driven by what actually happened
        // rather than by $request->statuses_id. Previously a failing mail server or a
        // slow supplier call sat inside the same request as the money movement.
        if ($outcome && $outcome->shouldFulfill) {
            FulfillSupplierOrderJob::dispatch((int) $id);
        }

        if ($outcome) {
            $this->notify($outcome, (int) $id);
        }

        return $redirect;
    }

    /**
     * Apply one status to many orders at once.
     *
     * The CMS shipped with exactly one bulk action (delete), so working a day's
     * orders meant opening each record, changing a dropdown, saving, and navigating
     * back. This is the same operation applied to a selection.
     *
     * CRITICAL: it routes through the very same applyStatusChange() the single-record
     * path uses. That is what guarantees a bulk approve cannot bypass refunds,
     * re-debits, the balance check or supplier fulfillment — there is no second
     * implementation of the money rules to drift out of sync.
     *
     * One transaction per order, not one for the batch: a single user who cannot
     * cover a re-debit should fail on their own row while the rest succeed.
     */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'string', 'max:10000'],
            'statuses_id' => ['required', Rule::in([
                Order::STATUS_APPROVED,
                Order::STATUS_REJECTED,
                Order::STATUS_PENDING,
            ])],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(500) // bound the work a single request can do
            ->values();

        $status = (int) $data['statuses_id'];
        $applied = 0;
        $unchanged = 0;
        $failures = [];
        $toFulfill = [];
        $toNotify = [];

        foreach ($ids as $id) {
            $order = Order::withoutGlobalScope('cms_draft_flag')->find($id);

            if (!$order) {
                continue;
            }

            $previous = (int) $order->statuses_id;

            if ($previous === $status) {
                $unchanged++;
                continue;
            }

            $order->statuses_id = $status;
            $order->saveQuietly();

            try {
                $outcome = $this->applyStatusChange($id);

                if ($outcome && $outcome->shouldFulfill) {
                    $toFulfill[] = $id;
                }

                if ($outcome) {
                    $toNotify[] = [$outcome, $id];
                }

                $applied++;
            } catch (InsufficientCreditsException $e) {
                // Put this one back and keep going.
                Order::withoutGlobalScope('cms_draft_flag')
                    ->where('id', $id)
                    ->update(['statuses_id' => $e->revertTo]);

                $failures[] = "#{$id}: " . $e->getMessage();
            }
        }

        // Dispatched after every transaction has committed.
        foreach ($toFulfill as $id) {
            FulfillSupplierOrderJob::dispatch($id);
        }

        // Same rule for the customer-facing side effects: a bulk approve of N orders
        // owes N customers a message, and it goes out through the same notify() the
        // single-record path uses so the two cannot say different things.
        foreach ($toNotify as [$outcome, $id]) {
            $this->notify($outcome, $id);
        }

        return back()->with('success', $this->bulkSummary($applied, $unchanged, $failures));
    }

    /** One human sentence describing what a bulk run actually did. */
    private function bulkSummary(int $applied, int $unchanged, array $failures): string
    {
        $parts = [];

        if ($applied) {
            $parts[] = "{$applied} updated";
        }

        if ($unchanged) {
            $parts[] = "{$unchanged} already had that status";
        }

        if ($failures) {
            $parts[] = count($failures) . ' could not be changed — ' . implode('; ', array_slice($failures, 0, 3))
                . (count($failures) > 3 ? ' …' : '');
        }

        return $parts ? implode('. ', $parts) . '.' : 'Nothing to update.';
    }

    /**
     * Move credits to match the order's persisted status, exactly once.
     *
     * THE RACE THIS REPLACES
     * ----------------------
     * The old flow read the status *before* calling the vendor update:
     *
     *     $previousStatus = $order->statuses_id;              // unlocked read
     *     $this->cmsPageController->update(...);              // status written
     *     $this->updateUserCredits($id, $request->statuses_id, $previousStatus);
     *
     * Two admins acting on the same order both observed the same $previousStatus, so
     * both took the same branch and the refund (or re-debit) was applied twice. The
     * lockForUpdate inside the transaction serialised the writes but could not undo a
     * decision that had already been made from stale data. It also trusted
     * $request->statuses_id — unvalidated request input — rather than what was saved.
     *
     * FIVE INDEPENDENT DEFENCES NOW
     *   1. `credits_applied_status` is state read under the row lock, not captured
     *      before the vendor call.
     *   2. The decision uses the re-read persisted `statuses_id`, never the request.
     *   3. Lock order is fixed everywhere — order row first, then user — matching
     *      SupplierOrderFulfillment, so these paths cannot deadlock against each other.
     *   4. CreditLedger's UNIQUE idempotency_key makes a duplicate impossible at the
     *      database level even if 1-3 were all wrong.
     *   5. total_purchases moves with the same delta, so it cannot drift from
     *      credits_balance.
     */
    private function applyStatusChange(int $orderId): ?StatusChangeOutcome
    {
        try {
            return DB::transaction(function () use ($orderId) {
                $order = Order::withoutGlobalScope('cms_draft_flag')
                    ->where('id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return null;
                }

                $applied = (int) ($order->credits_applied_status ?? Order::STATUS_PENDING);
                $current = (int) $order->statuses_id;

                if ($applied === $current) {
                    return null; // already settled — idempotent no-op
                }

                $user = User::where('id', $order->users_id)->lockForUpdate()->first();

                if (!$user) {
                    // Orphaned order (orders.users_id is nullable and unenforced).
                    // Record that the status moved so we do not retry every save.
                    $order->credits_applied_status = $current;
                    $order->saveQuietly();

                    return null;
                }

                $total = (float) $order->total_price;
                $delta = $this->deltaFor($applied, $current, $total);

                if ($delta < 0 && (float) $user->credits_balance < abs($delta)) {
                    throw new InsufficientCreditsException(
                        $applied,
                        (float) $user->credits_balance,
                        abs($delta)
                    );
                }

                if ($delta !== 0.0) {
                    CreditLedger::record(
                        $user,
                        $delta,
                        $delta > 0 ? CreditLedgerEntry::REASON_ORDER_REFUND : CreditLedgerEntry::REASON_ORDER_REDEBIT,
                        $order,
                        ['previous_status' => $applied, 'new_status' => $current],
                        "order:{$order->id}:{$applied}->{$current}"
                    );

                    // Lifetime spend moves opposite to the balance, in SQL so it can
                    // never disagree with what the ledger recorded.
                    DB::table('users')->where('id', $user->id)->update([
                        'total_purchases' => DB::raw('GREATEST(total_purchases + ' . number_format(-$delta, 4, '.', '') . ', 0)'),
                    ]);
                }

                $order->credits_applied_status = $current;
                $order->saveQuietly();

                return new StatusChangeOutcome(
                    from: $applied,
                    to: $current,
                    delta: $delta,
                    // Fulfil only on a genuine transition INTO approved, and only for
                    // a supplier order that has not already been placed.
                    shouldFulfill: $current === Order::STATUS_APPROVED
                        && $applied !== Order::STATUS_APPROVED
                        && blank($order->external_order_id)
                        && SupplierOrderFulfillment::isExternal($order),
                );
            }, 3); // retry on deadlock
        } catch (DuplicateLedgerEntryException $e) {
            // Another request applied this exact transition first. The transaction
            // rolled back, so nothing is half-done; this is a success, not an error.
            Log::info('Order status change already applied by a concurrent request', [
                'order_id' => $orderId,
                'key' => $e->idempotencyKey,
            ]);

            return null;
        }
    }

    /**
     * Tell the customer what was decided.
     *
     * Runs after the transaction has committed and never throws: the decision and the
     * credit movement are already durable, and an SMTP timeout must not be able to
     * undo them or fail the admin's save. Same contract as
     * Cms\CreditsController::notify().
     *
     * Driven by the StatusChangeOutcome — what actually happened under the row lock —
     * not by $request->statuses_id, so a no-op save sends nothing and a bulk run sends
     * exactly one message per order that genuinely moved.
     *
     * The approval mail deliberately does not quote a code. For a supplier order the
     * code does not exist yet at this point: FulfillSupplierOrderJob is dispatched from
     * the same commit and fills orders.code afterwards. It links to My Orders instead.
     */
    private function notify(StatusChangeOutcome $outcome, int $orderId): void
    {
        if (!in_array($outcome->to, [Order::STATUS_APPROVED, Order::STATUS_REJECTED], true)) {
            return;
        }

        $order = Order::withoutGlobalScope('cms_draft_flag')->find($orderId);

        if (!$order) {
            return;
        }

        try {
            NotificationController::createOrderStatusNotification(
                $order->users_id,
                $orderId,
                $outcome->to,
                $outcome->from,
                $order->total_price
            );
        } catch (\Throwable $e) {
            Log::error('Order status notification failed', ['order_id' => $orderId, 'exception' => $e]);
        }

        $user = User::find($order->users_id);

        if (!$user || blank($user->email)) {
            return;
        }

        $approved = $outcome->to === Order::STATUS_APPROVED;
        $view = $approved ? 'emails.order-approved' : 'emails.order-rejected';
        $subjectKey = $approved ? 'emails.subjects.order_approved' : 'emails.subjects.order_rejected';

        try {
            Mail::send($view, ['user' => $user, 'order' => $order], function ($message) use ($user, $subjectKey) {
                $message->to($user->email)->subject(__($subjectKey));
            });
        } catch (\Throwable $e) {
            Log::error('Order status email failed', ['order_id' => $orderId, 'exception' => $e]);
        }
    }

    /**
     * Signed credit delta for a status transition.
     *
     * Credits are HELD (debited from the user) while an order is pending or approved,
     * and RELEASED (refunded) when it is rejected. Expressing it that way collapses
     * the original five hand-written branches — which had to be read carefully to
     * confirm they were exhaustive — into one rule that is exhaustive by construction.
     *
     *   held → released   refund      (+total)
     *   released → held   re-debit    (-total)
     *   otherwise         no movement (0)
     *
     * Placement itself already debited the user (OrderController::saveOrder), which is
     * why pending → approved moves nothing.
     */
    private function deltaFor(int $applied, int $current, float $total): float
    {
        $wasHeld = $applied !== Order::STATUS_REJECTED;
        $isHeld = $current !== Order::STATUS_REJECTED;

        if ($wasHeld === $isHeld) {
            return 0.0;
        }

        return $wasHeld ? $total : -$total;
    }
}
