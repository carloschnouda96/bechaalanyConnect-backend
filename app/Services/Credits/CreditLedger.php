<?php

namespace App\Services\Credits;

use App\CreditLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The one place a credit balance may change.
 *
 * Every previous balance mutation was a bare `$user->credits_balance += $x; save();`
 * scattered across five files. That had three problems at once: float arithmetic
 * drifted, nothing recorded that the change had happened, and a read-modify-write in
 * PHP could interleave with another request.
 *
 * record() fixes all three:
 *   - the balance is moved with a SQL UPDATE (`increment`/`decrement`), so the
 *     arithmetic happens in the database on a DECIMAL column, not on a PHP float;
 *   - an immutable ledger row is written alongside, in the same transaction;
 *   - the UNIQUE `idempotency_key` makes replaying the same logical transition
 *     impossible at the database level.
 *
 * CONTRACT: callers MUST already be inside a transaction with the user row locked
 * via lockForUpdate(). That ordering is what serialises concurrent writers; this
 * class asserts the transaction but cannot verify the lock, so the call sites are
 * responsible for it. All of them are in App\Http\Controllers\Cms\* and
 * App\Services\Suppliers\SupplierOrderFulfillment.
 */
class CreditLedger
{
    /**
     * Apply a signed delta to a user's balance and record why.
     *
     * @param  \App\Models\User|\App\User  $lockedUser  already selected FOR UPDATE
     * @param  float|string  $delta  positive credits, negative debits
     * @param  string  $reason  a CreditLedgerEntry::REASON_* constant
     * @param  Model|null  $source  the order / credits-transfer that caused it
     * @param  string|null  $idempotencyKey  omit only for genuinely repeatable events
     * @return CreditLedgerEntry|null  null when this transition was already applied
     */
    public static function record(
        $lockedUser,
        $delta,
        string $reason,
        ?Model $source = null,
        array $meta = [],
        ?string $idempotencyKey = null,
        ?string $actorType = null,
        $actorId = null
    ): ?CreditLedgerEntry {
        if (DB::transactionLevel() < 1) {
            // Writing the balance and the ledger row outside a transaction could
            // leave them disagreeing, which defeats the entire purpose.
            throw new LogicException(
                'CreditLedger::record() must be called inside a transaction with the user row locked FOR UPDATE.'
            );
        }

        $delta = round((float) $delta, 4);

        if ($delta === 0.0 && $reason !== CreditLedgerEntry::REASON_OPENING_BALANCE) {
            return null;
        }

        [$actorType, $actorId] = self::resolveActor($actorType, $actorId);

        try {
            // Arithmetic in SQL on the DECIMAL column — never `$model->x += $y`.
            DB::table('users')->where('id', $lockedUser->id)->update([
                'credits_balance' => DB::raw('credits_balance + ' . self::sqlLiteral($delta)),
                'updated_at' => now(),
            ]);

            $balanceAfter = (float) DB::table('users')->where('id', $lockedUser->id)->value('credits_balance');

            $entry = CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'amount' => $delta,
                'balance_after' => $balanceAfter,
                'currency' => 'USD',
                'reason' => $reason,
                'source_type' => $source ? get_class($source) : null,
                'source_id' => $source?->getKey(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta ?: null,
            ]);

            // Keep the in-memory model consistent with what the database now holds,
            // so callers that go on to read ->credits_balance see the new value.
            $lockedUser->credits_balance = $balanceAfter;
            $lockedUser->syncOriginal();

            return $entry;
        } catch (QueryException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }

            // The transition was already applied — a retried job, a double-submitted
            // form, or two admins clicking approve at the same moment. The balance
            // update above is rolled back with the transaction, so this is a true
            // no-op rather than a partial application.
            Log::info('CreditLedger: transition already applied, skipping', [
                'user_id' => $lockedUser->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            throw new DuplicateLedgerEntryException($idempotencyKey ?? '');
        }
    }

    /**
     * Sum of every ledger entry for a user. Should always equal users.credits_balance;
     * `php artisan credits:reconcile` is what checks that.
     */
    public static function balanceFromLedger(int $userId): float
    {
        return (float) CreditLedgerEntry::where('user_id', $userId)->sum('amount');
    }

    /**
     * Default the actor to whoever is driving the current request: a signed-in CMS
     * admin, otherwise the authenticated API user, otherwise the system.
     */
    private static function resolveActor(?string $actorType, $actorId): array
    {
        if ($actorType !== null) {
            return [$actorType, $actorId];
        }

        if (Auth::guard('admin')->check()) {
            return [CreditLedgerEntry::ACTOR_ADMIN, Auth::guard('admin')->id()];
        }

        if (Auth::check()) {
            return [CreditLedgerEntry::ACTOR_USER, Auth::id()];
        }

        return [CreditLedgerEntry::ACTOR_SYSTEM, null];
    }

    /** Format for inline SQL; the value is a rounded float, never user input. */
    private static function sqlLiteral(float $delta): string
    {
        return number_format($delta, 4, '.', '');
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
