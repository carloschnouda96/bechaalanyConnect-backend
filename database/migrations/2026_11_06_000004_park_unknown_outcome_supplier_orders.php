<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Usharez and U-Manage orders whose outcome is unknown carried a synthetic id, so they were
 * on neither the Parked nor the Unplaced list of Supplier health, and raised no stale alarm.
 * Only the name of the id changes: non-null is what stops a re-send, so it is never cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereIn('external_source', ['usharez', 'umanage'])
            ->where('external_status', 'pending')
            ->where('statuses_id', 1)
            ->where('external_order_id', 'not like', 'parked-%')
            ->where(fn ($q) => $q
                ->where('external_response', 'like', '%usharez_indeterminate%')
                ->orWhere('external_response', 'like', '%umanage_indeterminate%'))
            ->orderBy('id')
            ->each(function ($order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'external_order_id' => 'parked-' . ($order->external_order_uuid ?: $order->id),
                ]);
            });
    }

    public function down(): void
    {
        // Nothing to undo: the old ids carried no information the row does not hold.
    }
};
