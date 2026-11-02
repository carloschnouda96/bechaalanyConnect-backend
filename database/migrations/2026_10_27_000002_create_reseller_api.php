<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller API: other stores buy from BechaalanyConnect programmatically.
 *
 *   api_keys                 one row per key a reseller generated. Only the
 *                            sha256 of the key is stored — the key itself is
 *                            shown once. Not a CMS table, so it is safe from
 *                            the CMS schema rewrite.
 *   users.api_enabled        admin switch (CMS Users page). A user can only
 *                            create keys, and a key only works, while it is on.
 *   orders.client_order_id   the reseller's own reference, UNIQUE per user, so
 *                            a retried POST /v1/orders returns the first order
 *                            instead of debiting twice.
 *   orders.source            'storefront' | 'api', so admins see where an
 *                            order came from.
 *
 * The three CMS-table columns are registered on their CMS pages — an
 * unregistered column is dropped by the next CMS schema save. `string` and
 * `boolean` both round-trip through the CMS's bare `->change()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->string('name', 100);
                // First characters of the key, shown in lists so a reseller can
                // tell keys apart without the full secret.
                $table->string('prefix', 16);
                $table->char('key_hash', 64)->unique();
                $table->json('allowed_ips')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (!Schema::hasColumn('users', 'api_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('api_enabled')->default(0);
            });
        }

        if (!Schema::hasColumn('orders', 'client_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('client_order_id')->nullable();
                $table->string('source')->nullable()->default('storefront');
                $table->unique(['users_id', 'client_order_id'], 'orders_user_client_order_unique');
            });
        }

        $this->addField('users', [
            'name' => 'api_enabled',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'description' => 'Allow this customer to create API keys and buy through the reseller API (/api/v1). Turning it off disables every key they have.',
            'hide_index' => 1,
            'hide_create' => 0,
            'hide_edit' => 0,
        ]);

        $this->addField('orders', [
            'name' => 'client_order_id',
            'migration_type' => 'string',
            'form_field' => 'text',
            'description' => "The reseller's own order reference (API orders only). Read-only.",
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
        ]);

        $this->addField('orders', [
            'name' => 'source',
            'migration_type' => 'string',
            'form_field' => 'text',
            'description' => 'Where the order was placed: storefront or api. Read-only.',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
        ]);
    }

    public function down(): void
    {
        // The CMS field registrations are left in place on purpose (see
        // 2026_08_10_000001): removing them would make the columns droppable again.
        if (Schema::hasColumn('orders', 'client_order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_user_client_order_unique');
                $table->dropColumn(['client_order_id', 'source']);
            });
        }

        if (Schema::hasColumn('users', 'api_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('api_enabled');
            });
        }

        Schema::dropIfExists('api_keys');
    }

    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (in_array($field['name'], array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = $field + [
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];

        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
