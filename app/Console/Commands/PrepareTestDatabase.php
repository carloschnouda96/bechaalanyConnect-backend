<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds (or rebuilds) the test database from the committed schema dump.
 *
 * Why a dump instead of migrations: roughly 15 of this project's 64 tables were
 * generated at runtime by the hellotree CMS and have no migration file, and
 * database/migrations/2014_10_12_000000_create_users_table.php actively contradicts
 * the live `users` table. `migrate:fresh` therefore produces a schema the
 * application cannot run against, and RefreshDatabase is unusable.
 *
 * The contract is:
 *   database/dumps/schema.sql          → structure (bootstrap source of truth)
 *   database/dumps/reference-data.sql  → the rows whose ids the code hardcodes
 *                                        (statuses 1/2/3, verification_statuses,
 *                                        languages, product_type, cms_pages)
 *   database/migrations/*              → the delta applied on top
 *
 * Refresh the dumps after any schema change:
 *   mysqldump --no-data ... bconnect > database/dumps/schema.sql
 */
class PrepareTestDatabase extends Command
{
    protected $signature = 'test:prepare-db
                            {--database=bconnect_testing : Name of the test database to (re)create}';

    protected $description = 'Create the test database from database/dumps/schema.sql + reference-data.sql';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Refusing to run outside local/testing.');

            return self::FAILURE;
        }

        $database = (string) $this->option('database');

        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            $this->error('Invalid database name.');

            return self::FAILURE;
        }

        // Guard against pointing this at the working database.
        if ($database === config('database.connections.mysql.database')) {
            $this->error("Refusing to drop `{$database}` — it is the configured application database.");

            return self::FAILURE;
        }

        $schema = database_path('dumps/schema.sql');
        $reference = database_path('dumps/reference-data.sql');

        foreach ([$schema, $reference] as $file) {
            if (!is_readable($file)) {
                $this->error('Missing dump: ' . $file);

                return self::FAILURE;
            }
        }

        $this->info("Recreating `{$database}` …");
        DB::statement("DROP DATABASE IF EXISTS `{$database}`");
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        foreach ([$schema => 'structure', $reference => 'reference data'] as $file => $label) {
            $this->line("  loading {$label} …");
            $this->runSqlFile($database, $file);
        }

        // Apply any migrations newer than the dump. This is what makes the documented
        // contract — dump bootstraps, migrations are the delta — actually hold: without
        // it, every schema migration would require re-dumping schema.sql before the
        // suite could run, and the failure mode is a confusing "Unknown column" deep
        // inside an unrelated test.
        $this->line('  applying migrations …');
        config()->set('database.connections.mysql.database', $database);
        DB::purge('mysql');

        $this->callSilent('migrate', ['--force' => true]);

        $tables = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database]
        )->c;

        $this->info("Done — {$database} has {$tables} tables.");
        $this->line('Run the suite with: php artisan test');

        return self::SUCCESS;
    }

    /**
     * Executed statement-by-statement over the existing PDO connection rather than
     * shelling out to `mysql`, whose binary lives in a different place on every
     * machine (MAMP nests it under Library/bin/mysql80/bin).
     */
    private function runSqlFile(string $database, string $file): void
    {
        $sql = file_get_contents($file);
        $pdo = DB::connection()->getPdo();
        $original = config('database.connections.mysql.database');

        $pdo->exec("USE `{$database}`");
        // The dumps contain no stored routines, so this can be executed as one batch.
        $pdo->exec($sql);
        $pdo->exec("USE `{$original}`");
    }
}
