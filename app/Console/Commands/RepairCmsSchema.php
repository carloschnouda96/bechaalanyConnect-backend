<?php

namespace App\Console\Commands;

use App\Services\Cms\SchemaGuard;
use App\Services\Cms\SchemaManifest;
use Illuminate\Console\Command;

/**
 * Detects and repairs schema drift caused by a hellotree CMS page-schema save.
 *
 *   php artisan cms:repair-schema             # repair everything in the manifest
 *   php artisan cms:repair-schema orders      # repair one table
 *   php artisan cms:repair-schema --check     # report only, non-zero exit on drift
 *
 * `--check` is the deploy gate: run it after migrating and after any CMS work, and
 * fail the deploy if it reports drift.
 */
class RepairCmsSchema extends Command
{
    protected $signature = 'cms:repair-schema
                            {table? : Limit to a single table}
                            {--check : Report drift without changing anything; exits 1 if any is found}';

    protected $description = 'Re-apply column types, nullability and indexes the CMS cannot preserve';

    public function handle(SchemaGuard $guard): int
    {
        $table = $this->argument('table');
        $check = (bool) $this->option('check');

        if ($table !== null && !in_array($table, SchemaManifest::tableNames(), true)) {
            $this->error("`{$table}` is not in the schema manifest.");
            $this->line('Known tables: ' . implode(', ', SchemaManifest::tableNames()));

            return self::FAILURE;
        }

        $result = $guard->repair($table, $check);

        if (!$result['applied'] && !$result['unrepairable']) {
            $this->info('Schema matches the manifest — no drift.');

            return self::SUCCESS;
        }

        foreach ($result['applied'] as $statement) {
            $this->line(($check ? '  [drift] ' : '  [fixed] ') . $statement);
        }

        foreach ($result['unrepairable'] as $issue) {
            $this->error('  [manual] ' . $issue);
        }

        $this->newLine();

        if ($result['unrepairable']) {
            $this->error(sprintf(
                '%d issue(s) need a human — a column is missing, or data blocks an index.',
                count($result['unrepairable'])
            ));

            return self::FAILURE;
        }

        if ($check) {
            $this->warn(sprintf('%d drift(s) detected. Run without --check to repair.', count($result['applied'])));

            return self::FAILURE;
        }

        $this->info(sprintf('%d drift(s) repaired.', count($result['applied'])));

        return self::SUCCESS;
    }
}
