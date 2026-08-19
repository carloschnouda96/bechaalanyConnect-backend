<?php

namespace App\Http\Controllers\Cms;

use App\CreditLedgerEntry;
use App\CreditsTransfer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Credits\StatusChangeOutcome;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreditsController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;

    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    public function update(Request $request, $id)
    {
        $requestedLocale = $request->get('lang') ?? $request->getPreferredLanguage(['en', 'ar']);
        if (in_array($requestedLocale, ['en', 'ar'])) {
            app()->setLocale($requestedLocale);
        }

        $request->validate([
            'statuses_id' => ['required', Rule::in([
                CreditsTransfer::STATUS_APPROVED,
                CreditsTransfer::STATUS_REJECTED,
                CreditsTransfer::STATUS_PENDING,
            ])],
        ]);

        $redirect = $this->cmsPageController->update($request, $id, 'credits-transfer', 'App\CreditsTransfer', self::class);

        try {
            $outcome = $this->applyStatusChange((int) $id);
        } catch (InsufficientCreditsException $e) {
            CreditsTransfer::withoutGlobalScope('cms_draft_flag')
                ->where('id', $id)
                ->update(['statuses_id' => $e->revertTo]);

            throw ValidationException::withMessages(['statuses_id' => [$e->getMessage()]]);
        }

        // Notification and email happen only when the money actually moved, and only
        // outside the transaction. Previously both were driven by the raw
        // $request->statuses_id, so they fired even when no credit change occurred,
        // and a mail failure could roll back the balance change.
        if ($outcome) {
            $this->notify($outcome, (int) $id);
        }

        return $redirect;
    }

    /**
     * Approve or reject many top-ups at once.
     *
     * Routes through the same applyStatusChange() as the single-record path, so a
     * bulk approval credits users through the ledger with the same idempotency
     * guarantee — there is no parallel implementation of the money rules.
     *
     * One transaction per transfer: reversing an approval the user has already spent
     * should fail on that row alone, not abort the whole batch.
     */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'string', 'max:10000'],
            'statuses_id' => ['required', Rule::in([
                CreditsTransfer::STATUS_APPROVED,
                CreditsTransfer::STATUS_REJECTED,
                CreditsTransfer::STATUS_PENDING,
            ])],
        ]);

        $ids = collect(explode(',', $data['ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(500)
            ->values();

        $status = (int) $data['statuses_id'];
        $applied = 0;
        $unchanged = 0;
        $failures = [];
        $outcomes = [];

        foreach ($ids as $id) {
            $transfer = CreditsTransfer::withoutGlobalScope('cms_draft_flag')->find($id);

            if (!$transfer) {
                continue;
            }

            if ((int) $transfer->statuses_id === $status) {
                $unchanged++;
                continue;
            }

            $transfer->statuses_id = $status;
            $transfer->saveQuietly();

            try {
                $outcome = $this->applyStatusChange($id);

                if ($outcome) {
                    $outcomes[$id] = $outcome;
                }

                $applied++;
            } catch (InsufficientCreditsException $e) {
                CreditsTransfer::withoutGlobalScope('cms_draft_flag')
                    ->where('id', $id)
                    ->update(['statuses_id' => $e->revertTo]);

                $failures[] = "#{$id}: " . $e->getMessage();
            }
        }

        // Emails and notifications only after all the money has committed.
        foreach ($outcomes as $id => $outcome) {
            $this->notify($outcome, $id);
        }

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

        return back()->with('success', $parts ? implode('. ', $parts) . '.' : 'Nothing to update.');
    }

    /**
     * Credit or reverse a top-up to match its persisted status, exactly once.
     *
     * THE RACE THIS REPLACES
     * ----------------------
     * $previousStatus was read before the vendor update and then used as the guard,
     * so two admins approving the same pending transfer both saw PENDING and both
     * took the "credit the user" branch — the amount was added to the balance twice.
     * The lockForUpdate serialised the two writes without preventing the second
     * credit, because the decision predated the lock.
     *
     * Now the decision is made from `credits_applied_status` read under the lock, and
     * CreditLedger's UNIQUE idempotency_key rejects a duplicate at the database level
     * regardless.
     */
    private function applyStatusChange(int $transferId): ?StatusChangeOutcome
    {
        try {
            return DB::transaction(function () use ($transferId) {
                $transfer = CreditsTransfer::withoutGlobalScope('cms_draft_flag')
                    ->where('id', $transferId)
                    ->lockForUpdate()
                    ->first();

                if (!$transfer) {
                    return null;
                }

                $applied = (int) ($transfer->credits_applied_status ?? CreditsTransfer::STATUS_PENDING);
                $current = (int) $transfer->statuses_id;

                if ($applied === $current) {
                    return null; // already settled
                }

                // Lock order: record first, then user — same as Cms\OrdersController
                // and SupplierOrderFulfillment, so these paths cannot deadlock.
                $user = User::where('id', $transfer->users_id)->lockForUpdate()->first();

                if (!$user) {
                    $transfer->credits_applied_status = $current;
                    $transfer->saveQuietly();

                    return null;
                }

                $amount = (float) $transfer->amount;
                $delta = $this->deltaFor($applied, $current, $amount);

                // Reversing an approval takes money back. If the user has already
                // spent it, refuse rather than drive the balance negative — the old
                // code subtracted unconditionally.
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
                        $delta > 0 ? CreditLedgerEntry::REASON_TOPUP_APPROVED : CreditLedgerEntry::REASON_TOPUP_REVERTED,
                        $transfer,
                        ['previous_status' => $applied, 'new_status' => $current],
                        "topup:{$transfer->id}:{$applied}->{$current}"
                    );

                    // received_amount tracks lifetime credited top-ups and moves with
                    // the balance, in SQL, so the two cannot diverge.
                    DB::table('users')->where('id', $user->id)->update([
                        'received_amount' => DB::raw('GREATEST(received_amount + ' . number_format($delta, 4, '.', '') . ', 0)'),
                    ]);
                }

                $transfer->credits_applied_status = $current;
                $transfer->saveQuietly();

                return new StatusChangeOutcome(from: $applied, to: $current, delta: $delta);
            }, 3);
        } catch (DuplicateLedgerEntryException $e) {
            Log::info('Credit transfer status change already applied by a concurrent request', [
                'transfer_id' => $transferId,
                'key' => $e->idempotencyKey,
            ]);

            return null;
        }
    }

    /**
     * Credits are HELD by the platform once a transfer is approved, and not otherwise.
     *
     *   not approved → approved   credit the user   (+amount)
     *   approved → not approved   take it back      (-amount)
     *   otherwise                 nothing
     */
    private function deltaFor(int $applied, int $current, float $amount): float
    {
        $wasCredited = $applied === CreditsTransfer::STATUS_APPROVED;
        $isCredited = $current === CreditsTransfer::STATUS_APPROVED;

        if ($wasCredited === $isCredited) {
            return 0.0;
        }

        return $isCredited ? $amount : -$amount;
    }

    /**
     * In-app notification plus, on approval, the confirmation email.
     *
     * Runs after the transaction has committed, so neither a mail failure nor a slow
     * SMTP handshake can roll back or delay a credit movement.
     */
    private function notify(StatusChangeOutcome $outcome, int $transferId): void
    {
        $transfer = CreditsTransfer::withoutGlobalScope('cms_draft_flag')->find($transferId);

        if (!$transfer) {
            return;
        }

        if (in_array($outcome->to, [CreditsTransfer::STATUS_APPROVED, CreditsTransfer::STATUS_REJECTED], true)) {
            try {
                NotificationController::createCreditNotification(
                    $transfer->users_id,
                    $transferId,
                    $outcome->to,
                    $outcome->from,
                    $transfer->amount
                );
            } catch (\Throwable $e) {
                Log::error('Credit status notification failed', ['transfer_id' => $transferId, 'exception' => $e]);
            }
        }

        if ($outcome->to !== CreditsTransfer::STATUS_APPROVED || !$outcome->moneyMoved()) {
            return;
        }

        $user = User::find($transfer->users_id);

        if (!$user || blank($user->email)) {
            return;
        }

        try {
            Mail::send('emails.credits_approved', ['user' => $user, 'amount' => $transfer->amount], function ($message) use ($user) {
                $message->to($user->email);
                // Was a hardcoded English string; the rest of the app's mail is localised.
                $message->subject(__('emails.subjects.credits_approved'));
            });
        } catch (\Throwable $e) {
            Log::error('Credits approved email failed', ['transfer_id' => $transferId, 'exception' => $e]);
        }
    }
}
