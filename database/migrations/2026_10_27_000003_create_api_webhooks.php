<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller API order-status webhooks: one endpoint per reseller.
 *
 *   url      https only, and never a private / loopback address (WebhookUrlGuard
 *            checks it on save and again, against fresh DNS, on every delivery).
 *   secret   the HMAC-SHA256 signing secret. Unlike an API key it has to be
 *            readable to sign with, so it is stored with Laravel's `encrypted`
 *            cast (APP_KEY), not hashed.
 *   last_*   the outcome of the most recent delivery, shown on
 *            /account-dashboard/api-keys so a reseller can see their endpoint is
 *            failing without asking us.
 *
 * Not a CMS table, so it is safe from the CMS schema rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_webhooks')) {
            return;
        }

        Schema::create('api_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('url', 2048);
            $table->text('secret');
            $table->timestamp('last_delivery_at')->nullable();
            // HTTP status of the last attempt; NULL with an error = no response.
            $table->unsignedSmallInteger('last_delivery_status')->nullable();
            $table->string('last_delivery_error', 500)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_webhooks');
    }
};
