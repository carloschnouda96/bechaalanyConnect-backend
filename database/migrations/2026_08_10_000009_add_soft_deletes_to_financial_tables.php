<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for the three tables that carry financial history.
 *
 * TWO REASONS
 *
 * 1. Deleting a user used to erase their money trail. Every pre-existing foreign key
 *    in this schema is ON DELETE CASCADE, so removing one user silently took their
 *    orders, their credit transfers and their notifications with it — with no
 *    recoverable record that any of it had happened.
 *
 * 2. It is now also required for the CMS to keep working. credit_ledger_entries.user_id
 *    is ON DELETE RESTRICT (a ledger that disappears with its subject is not an audit
 *    trail), so a hard delete of any user would now fail with a raw SQL constraint
 *    error in the admin's face. With SoftDeletes the CMS's `$record->delete()` becomes
 *    an UPDATE, so the button keeps working and the history survives.
 *
 * `deleted_at` is registered as a hidden CMS field — without that, the very next CMS
 * page save would drop the column (CmsPagesController::editDatabase:454-472) and every
 * soft-deleted row would silently reappear.
 */
return new class extends Migration
{
    /** table => cms route */
    private const TABLES = [
        'users' => 'users',
        'orders' => 'orders',
        'credits_transfer' => 'credits-transfer',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $route) {
            if (!Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->softDeletes();
                });
            }

            $this->registerCmsField($route);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $route) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropSoftDeletes());
            }

            $this->unregisterCmsField($route);
        }
    }

    private function registerCmsField(string $route): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];

        if (in_array('deleted_at', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'deleted_at',
            'migration_type' => 'datetime',
            'form_field' => 'date',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Internal: set when the record is soft-deleted. Managed by Eloquent — do not edit.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 1,
            'nullable' => '1',
            'unique' => '0',
        ];

        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    private function unregisterCmsField(string $route): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();

        if (!$page) {
            return;
        }

        $fields = array_values(array_filter(
            json_decode($page->fields, true) ?: [],
            fn ($field) => ($field['name'] ?? null) !== 'deleted_at'
        ));

        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
