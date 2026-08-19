<?php

namespace App\Observers;

use App\CreditLedgerEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Records balance changes that bypass CreditLedger.
 *
 * `credits_balance` is a plain number input on the CMS Users page, so an admin can
 * type a new balance and save. That path moves money with no ledger row, no reason
 * and no record of who did it — and it is exactly the path most likely to be used
 * when something has gone wrong and someone is "fixing" a balance by hand.
 *
 * This observer catches those writes and appends an `admin_adjustment` entry so the
 * ledger stays reconcilable. It deliberately does NOT block the edit: an admin
 * correcting a balance is legitimate, it just has to be attributable.
 *
 * CreditLedger's own writes go through the query builder (DB::table('users')->update),
 * which does not fire Eloquent events, so they cannot be double-counted here.
 */
class UserCreditsObserver
{
    public function updating($user): void
    {
        if (!$user->isDirty('credits_balance')) {
            return;
        }

        $before = (float) $user->getOriginal('credits_balance');
        $after = (float) $user->credits_balance;
        $delta = round($after - $before, 4);

        if ($delta === 0.0) {
            return;
        }

        try {
            CreditLedgerEntry::create([
                'user_id' => $user->id,
                'amount' => $delta,
                'balance_after' => $after,
                'currency' => 'USD',
                'reason' => CreditLedgerEntry::REASON_ADMIN_ADJUSTMENT,
                'source_type' => null,
                'source_id' => null,
                'actor_type' => Auth::guard('admin')->check()
                    ? CreditLedgerEntry::ACTOR_ADMIN
                    : (Auth::check() ? CreditLedgerEntry::ACTOR_USER : CreditLedgerEntry::ACTOR_SYSTEM),
                'actor_id' => Auth::guard('admin')->id() ?? Auth::id(),
                // No idempotency key: a manual adjustment is a one-off event, and two
                // identical corrections are two real events rather than a duplicate.
                'idempotency_key' => null,
                'meta' => [
                    'note' => 'Balance written directly on the model, outside CreditLedger',
                    'balance_before' => $before,
                ],
            ]);
        } catch (\Throwable $e) {
            // Never block the save. A missing audit line is bad; an admin unable to
            // correct a balance during an incident is worse.
            Log::error('Could not record admin balance adjustment', [
                'user_id' => $user->id,
                'delta' => $delta,
                'exception' => $e,
            ]);
        }
    }
}
