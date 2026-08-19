<?php

namespace Tests\Feature\Credits;

use App\CreditLedgerEntry;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The ledger is the mechanism that makes the double-credit race impossible, so these
 * tests target that guarantee directly rather than going through a controller.
 */
class CreditLedgerTest extends TestCase
{
    private function makeUser(float $balance = 0.0): \App\Models\User
    {
        return \App\Models\User::create([
            'username' => 'ledger_' . uniqid(),
            'email' => 'ledger_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => \App\Models\User::VERIFICATION_APPROVED,
        ]);
    }

    public function test_it_credits_and_records_an_entry(): void
    {
        $user = $this->makeUser(10.00);

        DB::transaction(function () use ($user) {
            $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
            CreditLedger::record($locked, 25.50, CreditLedgerEntry::REASON_TOPUP_APPROVED, null, [], "test:{$user->id}:credit");
        });

        $this->assertEquals(35.50, (float) $user->fresh()->credits_balance);

        $entry = CreditLedgerEntry::where('user_id', $user->id)->latest('id')->first();
        $this->assertEquals(25.50, (float) $entry->amount);
        $this->assertEquals(35.50, (float) $entry->balance_after);
        $this->assertSame(CreditLedgerEntry::REASON_TOPUP_APPROVED, $entry->reason);
    }

    public function test_it_debits(): void
    {
        $user = $this->makeUser(100.00);

        DB::transaction(function () use ($user) {
            $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
            CreditLedger::record($locked, -30.25, CreditLedgerEntry::REASON_ORDER_PLACED, null, [], "test:{$user->id}:debit");
        });

        $this->assertEquals(69.75, (float) $user->fresh()->credits_balance);
    }

    /**
     * THE double-credit test.
     *
     * Applying the same logical transition twice — which is exactly what two admins
     * clicking Approve at the same moment produces — must move the money once.
     */
    public function test_the_same_transition_applied_twice_moves_money_only_once(): void
    {
        $user = $this->makeUser(0.00);
        $key = "topup:{$user->id}:3->1";

        DB::transaction(function () use ($user, $key) {
            $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
            CreditLedger::record($locked, 500.00, CreditLedgerEntry::REASON_TOPUP_APPROVED, null, [], $key);
        });

        $this->assertEquals(500.00, (float) $user->fresh()->credits_balance);

        // Second attempt with the same key: rejected by the UNIQUE index.
        $this->expectException(DuplicateLedgerEntryException::class);

        try {
            DB::transaction(function () use ($user, $key) {
                $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                CreditLedger::record($locked, 500.00, CreditLedgerEntry::REASON_TOPUP_APPROVED, null, [], $key);
            });
        } finally {
            // The balance update inside the failed transaction was rolled back, so
            // the user was credited exactly once — not twice.
            $this->assertEquals(
                500.00,
                (float) $user->fresh()->credits_balance,
                'The duplicate transition must not have credited the user a second time.'
            );
            $this->assertSame(1, CreditLedgerEntry::where('idempotency_key', $key)->count());
        }
    }

    /**
     * CreditLedger::record() refuses to run outside a transaction, but that cannot be
     * asserted here: the suite uses DatabaseTransactions, so a transaction is always
     * already open and DB::transactionLevel() is never 0 inside a test.
     *
     * What is verifiable is that the guard exists and is reached before any write, so
     * the protection cannot be removed without this failing.
     */
    public function test_record_guards_against_running_outside_a_transaction(): void
    {
        $source = file_get_contents(app_path('Services/Credits/CreditLedger.php'));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*DB::transactionLevel\(\)\s*<\s*1\s*\)\s*\{.*?throw new LogicException/s',
            $source,
            'CreditLedger::record() must refuse to run outside a transaction — writing the '
            . 'balance and the ledger row non-atomically would let them disagree.'
        );
    }

    /**
     * Repeated credit/debit cycles must not drift. This is what DOUBLE could not
     * guarantee and DECIMAL plus SQL-side arithmetic does.
     */
    public function test_repeated_cycles_do_not_drift(): void
    {
        $user = $this->makeUser(0.00);

        for ($i = 0; $i < 60; $i++) {
            DB::transaction(function () use ($user, $i) {
                $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                CreditLedger::record($locked, 0.10, CreditLedgerEntry::REASON_TOPUP_APPROVED, null, [], "drift:{$user->id}:up:{$i}");
            });
            DB::transaction(function () use ($user, $i) {
                $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                CreditLedger::record($locked, -0.10, CreditLedgerEntry::REASON_ORDER_PLACED, null, [], "drift:{$user->id}:down:{$i}");
            });
        }

        $this->assertSame(
            '0.00',
            (string) $user->fresh()->credits_balance,
            '120 alternating 0.10 movements must land exactly on zero.'
        );
    }

    public function test_ledger_sum_matches_the_balance(): void
    {
        $user = $this->makeUser(0.00);

        foreach ([12.34, -5.00, 100.00, -7.89] as $i => $delta) {
            DB::transaction(function () use ($user, $delta, $i) {
                $locked = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();
                CreditLedger::record($locked, $delta, CreditLedgerEntry::REASON_ADMIN_ADJUSTMENT, null, [], "sum:{$user->id}:{$i}");
            });
        }

        $this->assertEqualsWithDelta(
            (float) $user->fresh()->credits_balance,
            CreditLedger::balanceFromLedger($user->id),
            0.0001
        );
    }
}
