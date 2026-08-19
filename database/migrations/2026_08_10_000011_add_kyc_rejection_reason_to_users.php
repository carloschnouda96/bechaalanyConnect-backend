<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `users.rejection_reason` so a rejected KYC submission can say what was wrong.
 *
 * emails.kyc-rejected currently tells the applicant only that their documents were
 * rejected and to "resubmit clear photos". It cannot say the ID was expired, the
 * selfie did not match, or the back of the card was cut off — so the applicant
 * resubmits the same thing and the admin rejects it again. `credits_transfer` already
 * has a `rejected_reason` column for exactly this; users never got one.
 *
 * NOTE ON THE COLUMN TYPE: registered as `text`, NOT `string`.
 * CmsPagesController re-asserts a registered column's type from an argument-less
 * Blueprint call on every page save, and `string` there means varchar(191) — which
 * would silently truncate a reason mid-sentence. `text` round-trips.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'rejection_reason')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('verification_statuses_id');
            });
        }

        $page = DB::table('cms_pages')->where('route', 'users')->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];

        if (in_array('rejection_reason', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'rejection_reason',
            'migration_type' => 'text',
            'form_field' => 'textarea',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Shown to the user in the rejection email. Explain what was wrong so they can fix it (e.g. "ID photo is cut off at the edge").',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];

        DB::table('cms_pages')->where('route', 'users')->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'rejection_reason')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('rejection_reason'));
        }

        $page = DB::table('cms_pages')->where('route', 'users')->first();

        if (!$page) {
            return;
        }

        DB::table('cms_pages')->where('route', 'users')->update([
            'fields' => json_encode(array_values(array_filter(
                json_decode($page->fields, true) ?: [],
                fn ($f) => ($f['name'] ?? null) !== 'rejection_reason'
            ))),
            'updated_at' => now(),
        ]);
    }
};
