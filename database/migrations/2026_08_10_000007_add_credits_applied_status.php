<?php

use App\CreditsTransfer;
use App\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `credits_applied_status` to `orders` and `credits_transfer`, and registers it
 * as a hidden CMS field so a page save cannot drop it.
 *
 * WHAT IT IS
 * The status the ledger currently reflects — i.e. "credits have been moved as far as
 * this status". It is written only inside the same transaction that moves the money,
 * with the row locked.
 *
 * WHY IT EXISTS
 * Both CMS controllers decided how to move credits by reading the row's status
 * *before* calling the vendor's update:
 *
 *     $previousStatus = $order->statuses_id;         // read, unlocked
 *     $this->cmsPageController->update(...);         // status written
 *     $this->updateUserCredits($id, $request->statuses_id, $previousStatus);
 *
 * Two admins approving the same pending top-up at the same moment both read
 * PENDING, so both take the "credit the user" branch and the amount is added twice.
 * The lockForUpdate() inside the transaction serialises the two writes but does not
 * stop the second one happening, because its decision was already made from stale
 * data. The same shape allowed double refunds and double re-debits on orders.
 *
 * With this column the decision is made from state read under the lock and compared
 * against what the ledger has actually applied, so a second attempt sees
 * applied == current and does nothing.
 *
 * Backfilled to each row's current status: existing rows have already had their
 * credits applied for the status they are in.
 */
return new class extends Migration
{
    private const TABLES = [
        'orders' => 'orders',
        'credits_transfer' => 'credits-transfer',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $route) {
            if (!Schema::hasColumn($table, 'credits_applied_status')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedTinyInteger('credits_applied_status')->nullable()->after('statuses_id');
                });
            }

            // Existing rows are already settled at their current status.
            DB::table($table)->whereNull('credits_applied_status')->update([
                'credits_applied_status' => DB::raw('statuses_id'),
            ]);

            $this->registerCmsField($route);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $route) {
            if (Schema::hasColumn($table, 'credits_applied_status')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('credits_applied_status'));
            }

            $this->unregisterCmsField($route);
        }
    }

    /**
     * Machine-owned: hidden from the index and both forms. An admin editing this by
     * hand would desynchronise the ledger from the balance.
     */
    private function registerCmsField(string $route): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];

        if (in_array('credits_applied_status', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'credits_applied_status',
            'migration_type' => 'tinyInteger',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Internal: the status for which credits have already been applied. Managed by the credit ledger — do not edit.',
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
            fn ($field) => ($field['name'] ?? null) !== 'credits_applied_status'
        ));

        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
