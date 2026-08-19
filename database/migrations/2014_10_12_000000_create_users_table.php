<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * WARNING: this migration does NOT describe the live `users` table.
     *
     * The real table has no `email_verified_at` and no `remember_token`, its
     * `phone_number` is a bigint rather than a string, and it carries roughly a dozen
     * further columns added by the CMS and by later migrations (credits_balance,
     * user_types_id, the KYC fields, deleted_at, …). Running it against an empty
     * database would produce a schema the application cannot work with.
     *
     * The bootstrap source of truth is database/dumps/schema.sql, applied by
     * `php artisan test:prepare-db`; this file is kept only so the migration history
     * stays contiguous. Guarded so it is a no-op wherever `users` already exists —
     * which, given the dump, is everywhere.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('country')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('verification_token')->nullable();
            $table->boolean('email_verified')->default(0);
            $table->string('password_reset_token')->nullable();
            $table->integer('account_verification_code')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
