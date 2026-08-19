<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves every money column onto DECIMAL.
 *
 * WHAT WAS WRONG
 * --------------
 *   orders.total_price               LONGTEXT   money stored as free text, then fed
 *                                               into SUM() by the CMS profit query —
 *                                               MySQL coerces non-numeric text to 0,
 *                                               silently under-reporting profit
 *   users.credits_balance            DOUBLE(8,2) binary float; every balance change is
 *   users.total_purchases            DOUBLE(8,2) a PHP `+=` on a float, so repeated
 *   users.received_amount            DOUBLE(8,2) approve/reject cycles drift, and all
 *                                               three were NULLable with no default
 *   credits_transfer.amount          INT        cannot hold cents at all — validation
 *                                               accepts `numeric`, so a $10.50 top-up
 *                                               was silently stored and credited as $10
 *   products_variations.price        DOUBLE     float money
 *   product_price_variations.price   DOUBLE(8,2) float money
 *
 * WHAT STAYS
 * ----------
 *   products_variations.cost_price   DOUBLE     deliberately unchanged. Perfect-Panel
 *                                               `Default` services price per 1000, so
 *                                               unitCost = rate/1000 is legitimately
 *                                               sub-cent (the smallest live value is
 *                                               0.00001005). Rounding it to 2dp makes
 *                                               computeSellingPrice() return 0 and the
 *                                               product sells for free. external_price
 *                                               decimal(20,8) remains the exact record.
 *
 * WHY DECIMAL(12,2) AND NOT (8,2)
 * -------------------------------
 * The CMS re-asserts column types from an argument-less Blueprint call, and `decimal`
 * there means decimal(8,2) — a $999,999.99 ceiling. That would have forced (8,2) on
 * everything, which is genuinely too small for `total_purchases`/`received_amount`
 * (lifetime cumulative totals for a reseller).
 *
 * App\Services\Cms\SchemaGuard now restores the true width immediately after every
 * CMS page save, so the wider type is safe. The one interaction to know about: if a
 * value ever exceeds 999,999.99, the CMS's own narrowing ALTER will fail under strict
 * mode and the admin's page save errors out. That is a loud, non-destructive failure —
 * money is never truncated — and `php artisan cms:repair-schema --check` reports it.
 *
 * SAFETY
 * ------
 * LONGTEXT → DECIMAL uses a shadow column, never an in-place MODIFY, and any row that
 * is not cleanly numeric is copied to `schema_conversion_quarantine` instead of being
 * silently zeroed. The migration aborts if anything lands in quarantine, so a human
 * decides what a malformed price meant.
 */
return new class extends Migration
{
    private const MONEY_TYPE = 'DECIMAL(12,2)';

    public function up(): void
    {
        $this->createQuarantineTable();

        $this->convertOrderTotalPrice();
        $this->convertUserBalances();
        $this->convertSimpleMoneyColumn('credits_transfer', 'amount');
        $this->convertSimpleMoneyColumn('products_variations', 'price');
        $this->convertSimpleMoneyColumn('product_price_variations', 'price');
    }

    public function down(): void
    {
        // Reverting types is possible; reverting rounding is not. A DECIMAL(12,2)
        // column cannot regenerate the binary-float noise it replaced, and a
        // quarantined LONGTEXT value is not restored by changing the type back.
        // Roll back by restoring the pre-migration dump instead — see the runbook in
        // CLAUDE.md. Types are reverted here only so `migrate:rollback` is not a
        // hard error.
        DB::statement('ALTER TABLE orders MODIFY COLUMN total_price LONGTEXT NULL');
        DB::statement('ALTER TABLE users MODIFY COLUMN credits_balance DOUBLE(8,2) NULL');
        DB::statement('ALTER TABLE users MODIFY COLUMN total_purchases DOUBLE(8,2) NULL');
        DB::statement('ALTER TABLE users MODIFY COLUMN received_amount DOUBLE(8,2) NULL');
        DB::statement('ALTER TABLE credits_transfer MODIFY COLUMN amount INT NULL');
        DB::statement('ALTER TABLE products_variations MODIFY COLUMN price DOUBLE NULL');
        DB::statement('ALTER TABLE product_price_variations MODIFY COLUMN price DOUBLE(8,2) NULL');
    }

    /**
     * Holds values that could not be converted, so nothing is lost silently.
     * Not a CMS-managed table, so editDatabase() can never touch it.
     */
    private function createQuarantineTable(): void
    {
        if (Schema::hasTable('schema_conversion_quarantine')) {
            return;
        }

        Schema::create('schema_conversion_quarantine', function (Blueprint $table) {
            $table->id();
            $table->string('table_name');
            $table->unsignedBigInteger('row_id');
            $table->string('column_name');
            $table->text('raw_value')->nullable();
            $table->string('reason');
            $table->timestamp('converted_at')->useCurrent();

            $table->index(['table_name', 'row_id']);
        });
    }

    /**
     * LONGTEXT → DECIMAL via a shadow column.
     *
     * Expected dirt in a text money column: '', '12.500000000000002' (a PHP float
     * cast to string), '1,250.00', '$12.50', '1e3'.
     */
    private function convertOrderTotalPrice(): void
    {
        if ($this->columnType('orders', 'total_price') === 'decimal(12,2)') {
            return; // already converted
        }

        // 1. Quarantine everything that will not convert cleanly.
        $dirty = DB::select("
            SELECT id, total_price
              FROM orders
             WHERE total_price IS NOT NULL
               AND TRIM(total_price) <> ''
               AND (total_price NOT REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
                    OR CAST(total_price AS DECIMAL(20,4)) > 9999999999.99
                    OR CAST(total_price AS DECIMAL(20,4)) < 0)
        ");

        foreach ($dirty as $row) {
            DB::table('schema_conversion_quarantine')->insert([
                'table_name' => 'orders',
                'row_id' => $row->id,
                'column_name' => 'total_price',
                'raw_value' => $row->total_price,
                'reason' => 'not a clean non-negative decimal',
                'converted_at' => now(),
            ]);
        }

        if (count($dirty) > 0) {
            throw new RuntimeException(sprintf(
                '%d order(s) have a total_price that cannot be converted to DECIMAL; they have been '
                . 'listed in schema_conversion_quarantine. Fix or write off those rows, then re-run. '
                . 'Order ids: %s',
                count($dirty),
                implode(', ', array_column($dirty, 'id'))
            ));
        }

        // 2. Shadow column, populate, swap. Never an in-place MODIFY: that would let
        //    MySQL coerce anything unexpected to 0 with only a warning.
        DB::statement('ALTER TABLE orders ADD COLUMN total_price_dec ' . self::MONEY_TYPE . ' NOT NULL DEFAULT 0.00 AFTER total_price');
        DB::statement("UPDATE orders SET total_price_dec = CAST(NULLIF(TRIM(total_price), '') AS DECIMAL(12,2))
                        WHERE total_price IS NOT NULL AND TRIM(total_price) <> ''");
        DB::statement('ALTER TABLE orders DROP COLUMN total_price');
        DB::statement('ALTER TABLE orders CHANGE total_price_dec total_price ' . self::MONEY_TYPE . ' NOT NULL DEFAULT 0.00');
    }

    /**
     * The three cumulative user money columns. NULL is backfilled to 0 first: they
     * were nullable with no default, so any user created outside the register/OAuth
     * paths (an admin adding one on the CMS Users page) had NULL balances, and
     * `NULL < $price` evaluates differently in SQL and PHP.
     */
    private function convertUserBalances(): void
    {
        foreach (['credits_balance', 'total_purchases', 'received_amount'] as $column) {
            if ($this->columnType('users', $column) === 'decimal(12,2)') {
                continue;
            }

            // Record any value that will move when rounded to 2dp — float drift that
            // has already happened, so the operator can see it rather than discover it.
            $drifted = DB::select("SELECT id, {$column} AS v FROM users
                                    WHERE {$column} IS NOT NULL AND {$column} <> ROUND({$column}, 2)");

            foreach ($drifted as $row) {
                DB::table('schema_conversion_quarantine')->insert([
                    'table_name' => 'users',
                    'row_id' => $row->id,
                    'column_name' => $column,
                    'raw_value' => (string) $row->v,
                    'reason' => 'float value rounded to 2dp during conversion',
                    'converted_at' => now(),
                ]);
            }

            DB::statement("UPDATE users SET {$column} = COALESCE({$column}, 0)");
            DB::statement("ALTER TABLE users MODIFY COLUMN {$column} " . self::MONEY_TYPE . ' NOT NULL DEFAULT 0.00');
        }
    }

    /**
     * Straight widening conversions. INT → DECIMAL and DOUBLE → DECIMAL are both safe
     * in place for these columns.
     *
     * Note on credits_transfer.amount: widening cannot recover the cents that the INT
     * column already truncated. Historical top-ups keep whatever whole number was
     * stored; only future ones can carry cents.
     */
    private function convertSimpleMoneyColumn(string $table, string $column): void
    {
        if ($this->columnType($table, $column) === 'decimal(12,2)') {
            return;
        }

        $drifted = DB::select("SELECT id, {$column} AS v FROM {$table}
                                WHERE {$column} IS NOT NULL AND {$column} <> ROUND({$column}, 2)");

        foreach ($drifted as $row) {
            DB::table('schema_conversion_quarantine')->insert([
                'table_name' => $table,
                'row_id' => $row->id,
                'column_name' => $column,
                'raw_value' => (string) $row->v,
                'reason' => 'value rounded to 2dp during conversion',
                'converted_at' => now(),
            ]);
        }

        DB::statement("UPDATE {$table} SET {$column} = COALESCE({$column}, 0)");
        DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} " . self::MONEY_TYPE . ' NOT NULL DEFAULT 0.00');
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->c;
    }
};
