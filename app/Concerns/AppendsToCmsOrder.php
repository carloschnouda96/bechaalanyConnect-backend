<?php

namespace App\Concerns;

/**
 * Gives a row created by code (supplier sync, CSV import) a slot at the END of the
 * admin's CMS drag-and-drop order, without disturbing any position already set.
 *
 * The vendor Order page lists every row of the table and rewrites `ht_pos` to a
 * GLOBAL 1..N (vendor main.js:359, CmsPageController::changeOrder), and the CMS
 * list sorts by `ht_pos` alone. So a new position must be unique across the whole
 * table — a per-parent `MAX(ht_pos)` (what the grouped sync used to do) collides
 * with another product's rows, and MySQL leaves tied rows in no defined order
 * under ORDER BY … LIMIT, which is what made the admin's order shuffle. NULL is no
 * better: it sorts first, so every import jumped to the top of the list.
 *
 * Only ever assign this to a row whose `ht_pos` is NULL — `ht_pos` is admin-owned
 * once set.
 */
trait AppendsToCmsOrder
{
    public static function nextHtPos(): int
    {
        return 1 + (int) static::withoutGlobalScope('cms_draft_flag')->max('ht_pos');
    }
}
