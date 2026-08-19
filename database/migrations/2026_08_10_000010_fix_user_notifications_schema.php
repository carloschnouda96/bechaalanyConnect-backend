<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two defects in `user_notifications`.
 *
 * 1. `data` is varchar(191) but holds a JSON payload that
 *    NotificationController::getAllNotifications json_decode()s. The longest live
 *    value is already 175 of the available 191 characters, so a slightly longer
 *    message silently truncates mid-JSON, json_decode returns null, and the API
 *    quietly serves a notification with null title/amount instead of failing. This
 *    is a bug that has not fired yet — converting to a real json column removes the
 *    cliff before it does.
 *
 * 2. There is no `type` column, yet UserNotification::scopeOfType() queries one
 *    (`where('type', $type)`). Any call throws "Unknown column". Adding the column
 *    and backfilling it from the payload makes the scope usable and lets the
 *    notifications UI filter server-side instead of pulling everything.
 *
 * Both columns are re-registered in cms_pages, without which the next page save
 * reverts `data` to varchar(191) and drops `type` entirely.
 *
 * `read_at` is also flipped to nullable in the CMS field list: it is registered
 * `nullable => 0`, which makes the CMS form demand a value for a column whose whole
 * purpose is to be NULL until the notification is read.
 */
return new class extends Migration
{
    private const ROUTE = 'user-notifications';

    public function up(): void
    {
        $this->convertDataToJson();
        $this->addTypeColumn();
        $this->updateCmsFields();
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_notifications', 'type')) {
            Schema::table('user_notifications', fn (Blueprint $t) => $t->dropColumn('type'));
        }

        DB::statement('ALTER TABLE user_notifications MODIFY COLUMN data VARCHAR(191) NULL');
    }

    /**
     * varchar(191) → json. MySQL rejects the conversion outright if any existing
     * value is not valid JSON, so anything already truncated is quarantined and
     * blanked first rather than blocking the migration.
     */
    private function convertDataToJson(): void
    {
        $type = DB::selectOne(
            'SELECT DATA_TYPE d FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "user_notifications" AND COLUMN_NAME = "data"'
        )?->d;

        if ($type === 'json') {
            return;
        }

        foreach (DB::table('user_notifications')->select('id', 'data')->get() as $row) {
            if ($row->data === null || json_decode($row->data, true) !== null) {
                continue;
            }

            if (Schema::hasTable('schema_conversion_quarantine')) {
                DB::table('schema_conversion_quarantine')->insert([
                    'table_name' => 'user_notifications',
                    'row_id' => $row->id,
                    'column_name' => 'data',
                    'raw_value' => $row->data,
                    'reason' => 'payload was truncated by varchar(191) and no longer parses as JSON',
                    'converted_at' => now(),
                ]);
            }

            // Keep the notification row (it still has a user, status and timestamp)
            // but replace the unparseable payload with a valid empty object.
            DB::table('user_notifications')->where('id', $row->id)->update(['data' => '{}']);
        }

        DB::statement('UPDATE user_notifications SET data = "{}" WHERE data IS NULL OR TRIM(data) = ""');
        DB::statement('ALTER TABLE user_notifications MODIFY COLUMN data JSON NULL');
    }

    /**
     * Backfilled from the payload's new_status using the same mapping
     * NotificationController already applies when it builds a notification.
     */
    private function addTypeColumn(): void
    {
        if (!Schema::hasColumn('user_notifications', 'type')) {
            Schema::table('user_notifications', function (Blueprint $table) {
                $table->string('type', 40)->nullable()->after('data');
            });
        }

        DB::statement("
            UPDATE user_notifications
               SET type = CASE
                   WHEN JSON_EXTRACT(data, '$.type') IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(data, '$.type'))
                   WHEN JSON_EXTRACT(data, '$.new_status') = 1 THEN 'approved'
                   WHEN JSON_EXTRACT(data, '$.new_status') = 2 THEN 'rejected'
                   WHEN JSON_EXTRACT(data, '$.new_status') = 3 THEN 'pending'
                   ELSE 'general'
               END
             WHERE type IS NULL
        ");
    }

    private function updateCmsFields(): void
    {
        $page = DB::table('cms_pages')->where('route', self::ROUTE)->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];

        foreach ($fields as &$field) {
            if (($field['name'] ?? null) === 'data') {
                $field['migration_type'] = 'json';
                $field['form_field'] = 'textarea';
                $field['nullable'] = '1';
            }

            // read_at is NULL until the notification is read; requiring it in the CMS
            // form was simply wrong.
            if (($field['name'] ?? null) === 'read_at') {
                $field['nullable'] = '1';
            }
        }
        unset($field);

        if (!in_array('type', array_column($fields, 'name'), true)) {
            $fields[] = [
                'name' => 'type',
                'migration_type' => 'string',
                'form_field' => 'text',
                'form_field_additionals_1' => null,
                'form_field_additionals_2' => null,
                'description' => 'Notification category (approved / rejected / pending / general). Set when the notification is created.',
                'hide_index' => 0,
                'hide_create' => 1,
                'hide_edit' => 1,
                'hide_show' => 0,
                'nullable' => '1',
                'unique' => '0',
            ];
        }

        DB::table('cms_pages')->where('route', self::ROUTE)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
