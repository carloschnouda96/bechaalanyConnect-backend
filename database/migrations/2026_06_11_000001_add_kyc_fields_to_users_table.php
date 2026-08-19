<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds KYC (identity verification) columns to users and creates the
     * verification_statuses lookup table (mirrors the CMS "statuses" table
     * shape so it can back a CMS select field).
     *
     * Made idempotent 2026-08-13 after production's `migrate` failed here:
     * production's schema already had `verification_statuses` and the users
     * KYC columns before this migration ever ran there (almost certainly
     * seeded from a schema snapshot, the same class of issue documented for
     * the local dev DB in CLAUDE.md). Two of the original statements were
     * genuinely unsafe to re-run against a database in that state:
     *
     *   - the seed insert used hardcoded ids 1-4, which would violate the
     *     primary key if the table already had rows;
     *   - the trailing `UPDATE users SET verification_statuses_id = 3` was
     *     UNCONDITIONAL. If real users already had real KYC statuses set
     *     (plausible, since the columns already existed), this would have
     *     silently overwritten every user - including anyone genuinely
     *     Pending or Rejected - back to Approved. It is now scoped to users
     *     whose status is still NULL, which is what "grandfather existing
     *     users" actually means.
     */
    public function up(): void
    {
        if (!Schema::hasTable('verification_statuses')) {
            Schema::create('verification_statuses', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('cms_draft_flag')->default(0);
                $table->string('title')->nullable();
                $table->integer('ht_pos')->nullable();
                $table->timestamps();
            });
        }

        if (DB::table('verification_statuses')->count() === 0) {
            DB::table('verification_statuses')->insert([
                ['id' => 1, 'title' => 'Unsubmitted', 'ht_pos' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'title' => 'Pending', 'ht_pos' => 2, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 3, 'title' => 'Approved', 'ht_pos' => 3, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 4, 'title' => 'Rejected', 'ht_pos' => 4, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'id_front_image')) {
                $table->string('id_front_image')->nullable();
            }
            if (!Schema::hasColumn('users', 'id_back_image')) {
                $table->string('id_back_image')->nullable();
            }
            if (!Schema::hasColumn('users', 'selfie_image')) {
                $table->string('selfie_image')->nullable();
            }
            if (!Schema::hasColumn('users', 'verification_statuses_id')) {
                $table->unsignedInteger('verification_statuses_id')->nullable();
            }
        });

        // Grandfather existing users as approved so KYC only applies to new
        // signups. Scoped to NULL so this never overwrites a real status a
        // user already has (see idempotency note above).
        DB::table('users')->whereNull('verification_statuses_id')->update(['verification_statuses_id' => 3]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['id_front_image', 'id_back_image', 'selfie_image', 'verification_statuses_id']);
        });

        Schema::dropIfExists('verification_statuses');
    }
};
