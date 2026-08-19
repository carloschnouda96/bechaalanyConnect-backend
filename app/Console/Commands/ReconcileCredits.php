<?php

namespace App\Console\Commands;

use App\CreditLedgerEntry;
use App\Services\Credits\CreditLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks the invariant that makes the ledger worth having:
 *
 *     SUM(credit_ledger_entries.amount) == users.credits_balance   for every user
 *
 * If those two ever disagree, money moved through a path that did not record itself.
 * That is the signal that a double-credit, a missed refund, or a hand-edited balance
 * has happened — the class of bug that was previously invisible.
 *
 *   php artisan credits:reconcile           # report
 *   php artisan credits:reconcile --fix     # write a `correction` entry per drift
 *
 * Run it before and after any money migration (the outputs must match), and nightly
 * via /api/cron/credits/reconcile.
 */
class ReconcileCredits extends Command
{
    protected $signature = 'credits:reconcile
                            {--fix : Append a correction entry so the ledger matches the stored balance}';

    protected $description = 'Verify every user balance against the credit ledger';

    public function handle(): int
    {
        $drifted = DB::table('users')
            ->leftJoin('credit_ledger_entries', 'credit_ledger_entries.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.username', 'users.email', 'users.credits_balance')
            ->havingRaw('ABS(users.credits_balance - COALESCE(SUM(credit_ledger_entries.amount), 0)) > 0.0001')
            ->select([
                'users.id',
                'users.username',
                'users.email',
                'users.credits_balance',
                DB::raw('COALESCE(SUM(credit_ledger_entries.amount), 0) AS ledger_total'),
            ])
            ->get();

        $checked = DB::table('users')->count();

        if ($drifted->isEmpty()) {
            $this->info("Ledger matches every balance ({$checked} user(s) checked).");

            return self::SUCCESS;
        }

        $this->error(sprintf('%d of %d user(s) have a balance the ledger cannot explain:', $drifted->count(), $checked));
        $this->newLine();

        $this->table(
            ['user', 'email', 'balance', 'ledger', 'difference'],
            $drifted->map(fn ($row) => [
                $row->id . ' ' . $row->username,
                $row->email,
                number_format((float) $row->credits_balance, 2),
                number_format((float) $row->ledger_total, 2),
                number_format((float) $row->credits_balance - (float) $row->ledger_total, 2),
            ])->all()
        );

        Log::warning('Credit ledger reconciliation found drift', [
            'count' => $drifted->count(),
            'user_ids' => $drifted->pluck('id')->all(),
        ]);

        if (!$this->option('fix')) {
            $this->newLine();
            $this->warn('Investigate before running --fix: a difference means money moved somewhere that did not record itself.');
            $this->line('Re-run with --fix once you are satisfied the stored balance is the correct one.');

            return self::FAILURE;
        }

        foreach ($drifted as $row) {
            $difference = round((float) $row->credits_balance - (float) $row->ledger_total, 4);

            // Treats users.credits_balance as authoritative and makes the ledger
            // agree — never the other way around. Silently rewriting someone's
            // balance to match a ledger that is missing entries would turn a
            // reporting problem into a money problem.
            CreditLedgerEntry::create([
                'user_id' => $row->id,
                'amount' => $difference,
                'balance_after' => $row->credits_balance,
                'currency' => 'USD',
                'reason' => CreditLedgerEntry::REASON_CORRECTION,
                'actor_type' => CreditLedgerEntry::ACTOR_SYSTEM,
                'idempotency_key' => null,
                'meta' => [
                    'note' => 'Reconciliation: ledger did not account for the stored balance',
                    'ledger_total_before' => (float) $row->ledger_total,
                ],
            ]);

            $this->line("  user {$row->id}: wrote correction of " . number_format($difference, 2));
        }

        $this->newLine();
        $this->info('Corrections written. Re-run without --fix to confirm the invariant now holds.');

        return self::SUCCESS;
    }
}
