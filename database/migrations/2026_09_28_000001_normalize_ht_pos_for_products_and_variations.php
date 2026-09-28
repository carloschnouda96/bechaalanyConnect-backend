<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renumbers products / products_variations `ht_pos` to a unique 1..N, keeping the
 * order the admin already sees.
 *
 * The CMS Order page and list sort each table by `ht_pos` alone, table-wide. The
 * supplier sync used to leave new rows at NULL (sorted first, in no defined order)
 * or, for grouped categories, give them a per-product `MAX + 1` that collided with
 * other products' positions. MySQL puts tied rows in no guaranteed order under
 * ORDER BY … LIMIT, so the admin's drag-and-drop order appeared to shuffle after
 * every import. New rows now get App\Concerns\AppendsToCmsOrder::nextHtPos(); this
 * repairs the rows written before that.
 *
 * Order kept: `ht_pos IS NULL, ht_pos, id` — every admin-set position keeps its
 * relative place (negative ones from CMS-created rows included), ties break by
 * import order, and never-ordered NULL rows go to the end, exactly where a new
 * import lands from now on. Raw query-builder updates, so no model observer fires
 * (ProductsVariationObserver would otherwise look at a price-less save).
 *
 * Idempotent: on an already-normalized table no row changes. down() is a no-op —
 * the old ties had no defined order to restore.
 */
return new class extends Migration
{
    private const TABLES = ['products_variations', 'products'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::transaction(function () use ($table) {
                $rows = DB::table($table)
                    ->select('id', 'ht_pos')
                    ->orderByRaw('ht_pos IS NULL, ht_pos, id')
                    ->lockForUpdate()
                    ->get();

                $position = 0;
                foreach ($rows as $row) {
                    $position++;
                    if ($row->ht_pos === null || (int) $row->ht_pos !== $position) {
                        DB::table($table)->where('id', $row->id)->update(['ht_pos' => $position]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
