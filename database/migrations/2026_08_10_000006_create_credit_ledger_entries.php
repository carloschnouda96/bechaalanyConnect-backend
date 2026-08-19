<?php

use App\CreditLedgerEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for every movement of a user's credit balance.
 *
 * There was none. `users.credits_balance` was mutated by a bare PHP `+=` in five
 * places (OrderController::saveOrder, both CMS controllers across four status
 * branches, SupplierOrderFulfillment::refund) and was also directly editable as a
 * plain number input on the CMS Users page. So when a balance looked wrong there was
 * no way to answer "what happened, when, and who did it" — and no way to detect that
 * the double-credit race had fired.
 *
 * DESIGN NOTES
 *
 * - NOT a CMS-managed table (no cms_pages row), so editDatabase() can never drop a
 *   column, revert a type, or force it NULLable.
 *
 * - `idempotency_key` UNIQUE is the structural fix for the double-credit race. Even
 *   if every application-level guard is wrong, the second attempt to apply the same
 *   transition violates the unique index and is rejected by the database. That is a
 *   much stronger guarantee than "the code reads the status carefully".
 *
 * - `balance_after` snapshots the resulting balance so the ledger can be replayed and
 *   reconciled against users.credits_balance without re-deriving it.
 *
 * - decimal(18,4), wider than the 2dp money columns, because supplier refunds can
 *   carry sub-cent amounts from per-1000 pricing.
 *
 * - ON DELETE RESTRICT on user_id: deleting a user must not erase their financial
 *   history. (Every existing FK in this schema is ON DELETE CASCADE, which is how
 *   deleting a user currently wipes their orders and top-ups outright.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('credit_ledger_entries')) {
            Schema::create('credit_ledger_entries', function (Blueprint $table) {
                $table->id();

                $table->unsignedInteger('user_id');

                // Signed: positive credits the user, negative debits them.
                $table->decimal('amount', 18, 4);
                $table->decimal('balance_after', 18, 4);
                $table->char('currency', 3)->default('USD');

                $table->string('reason', 40);

                // Polymorphic pointer back to the order / transfer that caused this.
                $table->string('source_type', 64)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();

                // Who caused it: the user themselves, an admin, or the system
                // (supplier refund job, reconciliation).
                $table->string('actor_type', 16)->default('system');
                $table->unsignedBigInteger('actor_id')->nullable();

                // Makes replaying a transition a no-op instead of a double charge.
                $table->string('idempotency_key', 120)->nullable();

                $table->json('meta')->nullable();

                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['source_type', 'source_id']);
                $table->unique('idempotency_key');

                $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            });
        }

        $this->seedOpeningBalances();
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
    }

    /**
     * Give every existing user one `opening_balance` entry equal to their current
     * balance, so the invariant SUM(ledger.amount) == users.credits_balance holds
     * from the first day and `credits:reconcile` is meaningful immediately.
     *
     * Without this every pre-existing user would look like they were carrying an
     * unexplained balance forever.
     */
    private function seedOpeningBalances(): void
    {
        $users = DB::table('users')
            ->select('id', 'credits_balance')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('credit_ledger_entries')
                    ->whereColumn('credit_ledger_entries.user_id', 'users.id');
            })
            ->get();

        $now = now();

        foreach ($users->chunk(200) as $chunk) {
            DB::table('credit_ledger_entries')->insert(
                $chunk->map(fn ($user) => [
                    'user_id' => $user->id,
                    'amount' => $user->credits_balance ?? 0,
                    'balance_after' => $user->credits_balance ?? 0,
                    'currency' => 'USD',
                    'reason' => CreditLedgerEntry::REASON_OPENING_BALANCE,
                    'source_type' => null,
                    'source_id' => null,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'idempotency_key' => 'opening:' . $user->id,
                    'meta' => json_encode(['note' => 'Balance carried in when the ledger was introduced']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }
};
