<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes storage nothing reads any more.
 *
 * - users.is_business_user: always 0 (RegisteredUserController hardcoded it) and never
 *   read. Business pricing is `users.user_types_id`, set by an admin. It still rendered
 *   as an editable checkbox on the CMS Users page that did nothing. Unregistered from
 *   cms_pages.fields FIRST, then dropped — the other order would leave a registered
 *   field with no column, which breaks the Users form.
 * - password_reset_tokens: Laravel's default broker table. This app keeps its reset
 *   token on users.password_reset_token and never calls the Password broker, so the table
 *   was always empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if ($page) {
            $fields = array_values(array_filter(
                json_decode($page->fields, true) ?: [],
                fn ($f) => $f['name'] !== 'is_business_user'
            ));
            DB::table('cms_pages')->where('id', $page->id)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('users', 'is_business_user')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_business_user');
            });
        }

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasColumn('users', 'is_business_user')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('is_business_user')->nullable()->after('account_verification_code');
            });
        }

        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if ($page) {
            $fields = json_decode($page->fields, true) ?: [];
            if (!in_array('is_business_user', array_column($fields, 'name'), true)) {
                $position = array_search('account_verification_code', array_column($fields, 'name'), true);
                array_splice($fields, $position === false ? count($fields) : $position + 1, 0, [[
                    'name' => 'is_business_user',
                    'migration_type' => 'integer',
                    'form_field' => 'checkbox',
                    'form_field_additionals_1' => null,
                    'form_field_additionals_2' => null,
                    'description' => null,
                    'hide_index' => 0,
                    'hide_create' => 0,
                    'hide_edit' => 0,
                    'hide_show' => 0,
                    'nullable' => '1',
                    'unique' => '0',
                ]]);
                DB::table('cms_pages')->where('id', $page->id)->update([
                    'fields' => json_encode($fields),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
