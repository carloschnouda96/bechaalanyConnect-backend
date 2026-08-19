<?php

namespace App\Services\Cms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Re-applies the schema facts the hellotree CMS overwrites when an admin saves a
 * page schema.
 *
 * Runs after CmsPagesController::update() (see App\Http\Controllers\Cms\CmsPagesController)
 * and on demand via `php artisan cms:repair-schema`.
 *
 * Idempotent: it reads the live schema from information_schema first and only
 * issues DDL for genuine differences, so calling it repeatedly is free.
 *
 * It deliberately does NOT create missing columns. A column that has been dropped
 * has already lost its data, and silently recreating it empty would turn a loud
 * failure into quiet corruption — the sync would repopulate some rows and not
 * others. Missing columns are reported so a human decides.
 */
class SchemaGuard
{
    /** @var array<int, string> DDL actually executed during the last run. */
    private array $applied = [];

    /** @var array<int, string> Differences that need a human. */
    private array $unrepairable = [];

    /**
     * @param  string|null  $table  Limit to one table; null repairs everything in the manifest.
     * @return array{applied: array<int,string>, unrepairable: array<int,string>}
     */
    public function repair(?string $table = null, bool $dryRun = false): array
    {
        $this->applied = [];
        $this->unrepairable = [];

        $manifest = SchemaManifest::tables();

        if ($table !== null) {
            $manifest = array_intersect_key($manifest, [$table => true]);
        }

        foreach ($manifest as $tableName => $spec) {
            if (!Schema::hasTable($tableName)) {
                $this->unrepairable[] = "table `{$tableName}` does not exist";
                continue;
            }

            $this->repairColumns($tableName, $spec['columns'] ?? [], $dryRun);
            $this->repairIndexes($tableName, $spec['indexes'] ?? [], $dryRun);
        }

        if ($this->applied && !$dryRun) {
            // A repair means a CMS save just silently changed the schema. That is
            // worth a log line even though it was fixed automatically.
            Log::warning('SchemaGuard repaired CMS-induced schema drift', [
                'table' => $table,
                'statements' => $this->applied,
            ]);
        }

        if ($this->unrepairable) {
            Log::error('SchemaGuard found drift it cannot repair', [
                'table' => $table,
                'issues' => $this->unrepairable,
            ]);
        }

        return ['applied' => $this->applied, 'unrepairable' => $this->unrepairable];
    }

    /**
     * @param  array<string, array{type: string, null?: bool, default?: string|null}>  $columns
     */
    private function repairColumns(string $table, array $columns, bool $dryRun): void
    {
        foreach ($columns as $column => $spec) {
            $live = $this->liveColumn($table, $column);

            if ($live === null) {
                $this->unrepairable[] = "`{$table}`.`{$column}` is missing "
                    . '(dropped by a CMS save? register it in cms_pages.fields and restore it by migration)';
                continue;
            }

            $wantType = strtolower($spec['type']);
            $wantNull = $spec['null'] ?? true;
            $wantDefault = $spec['default'] ?? null;

            $haveType = strtolower($live->COLUMN_TYPE);
            $haveNull = $live->IS_NULLABLE === 'YES';
            $haveDefault = $live->COLUMN_DEFAULT;

            $matches = $haveType === $wantType
                && $haveNull === $wantNull
                && (string) $haveDefault === (string) $wantDefault;

            if ($matches) {
                continue;
            }

            $sql = sprintf(
                'ALTER TABLE `%s` MODIFY COLUMN `%s` %s %s%s',
                $table,
                $column,
                $spec['type'],
                $wantNull ? 'NULL' : 'NOT NULL',
                $wantDefault === null
                    ? ($wantNull ? ' DEFAULT NULL' : '')
                    : ' DEFAULT ' . DB::getPdo()->quote($wantDefault)
            );

            $this->applied[] = sprintf(
                '%s   -- was %s %s',
                $sql,
                $haveType,
                $haveNull ? 'NULL' : 'NOT NULL'
            );

            if (!$dryRun) {
                DB::statement($sql);
            }
        }
    }

    /**
     * @param  array<string, array{columns: array<int,string>, unique?: bool}>  $indexes
     */
    private function repairIndexes(string $table, array $indexes, bool $dryRun): void
    {
        foreach ($indexes as $name => $spec) {
            if ($this->indexExists($table, $name)) {
                continue;
            }

            // Only create the index if every column it needs is actually present.
            foreach ($spec['columns'] as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $this->unrepairable[] = "index `{$name}` on `{$table}` cannot be created: column `{$column}` is missing";
                    continue 2;
                }
            }

            $sql = sprintf(
                'ALTER TABLE `%s` ADD %s `%s` (%s)',
                $table,
                ($spec['unique'] ?? false) ? 'UNIQUE' : 'INDEX',
                $name,
                implode(', ', array_map(fn ($c) => "`{$c}`", $spec['columns']))
            );

            if ($dryRun) {
                $this->applied[] = $sql . '   -- missing';
                continue;
            }

            try {
                DB::statement($sql);
                $this->applied[] = $sql;
            } catch (\Throwable $e) {
                // A UNIQUE index fails when the data already contains duplicates.
                // That is a data problem, not a schema one, and must not be silently
                // swallowed or retried on every admin page save.
                $this->unrepairable[] = "index `{$name}` on `{$table}` could not be created: " . $e->getMessage();
            }
        }
    }

    private function liveColumn(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS found
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              LIMIT 1',
            [$table, $index]
        );

        return $row !== null;
    }
}
