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
     */
    public function up(): void
    {
        Schema::create('verification_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cms_draft_flag')->default(0);
            $table->string('title')->nullable();
            $table->integer('ht_pos')->nullable();
            $table->timestamps();
        });

        DB::table('verification_statuses')->insert([
            ['id' => 1, 'title' => 'Unsubmitted', 'ht_pos' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'title' => 'Pending', 'ht_pos' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'title' => 'Approved', 'ht_pos' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'title' => 'Rejected', 'ht_pos' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('id_front_image')->nullable();
            $table->string('id_back_image')->nullable();
            $table->string('selfie_image')->nullable();
            $table->unsignedInteger('verification_statuses_id')->nullable();
        });

        // Grandfather all existing users as approved so KYC only applies to new signups
        DB::table('users')->update(['verification_statuses_id' => 3]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['id_front_image', 'id_back_image', 'selfie_image', 'verification_statuses_id']);
        });

        Schema::dropIfExists('verification_statuses');
    }
};
