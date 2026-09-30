<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the Orders and Credits Transfer page schemas saveable again.
 *
 * `credits_applied_status` was registered with migration_type `tinyInteger`. The CMS
 * re-asserts every column with `->{migration_type}()->nullable()->change()`, which goes
 * through Doctrine DBAL — and DBAL has no "tinyinteger" type. So every save of either
 * page threw "Unknown column type tinyinteger" half-way through the column loop: the
 * columns before it had already been narrowed (orders.total_price to decimal(8,2) NULL),
 * and the exception skipped SchemaGuard, leaving the drift in place until someone ran
 * `cms:repair-schema`.
 *
 * `integer` round-trips through DBAL; SchemaManifest already restores the real
 * `tinyint(3) unsigned` after every save.
 */
return new class extends Migration
{
    private const ROUTES = ['orders', 'credits-transfer'];

    public function up(): void
    {
        $this->setType('tinyInteger', 'integer');
    }

    public function down(): void
    {
        $this->setType('integer', 'tinyInteger');
    }

    private function setType(string $from, string $to): void
    {
        foreach (self::ROUTES as $route) {
            $page = DB::table('cms_pages')->where('route', $route)->first();
            if (!$page) {
                continue;
            }

            $fields = json_decode($page->fields, true) ?: [];
            foreach ($fields as &$field) {
                if ($field['name'] === 'credits_applied_status' && $field['migration_type'] === $from) {
                    $field['migration_type'] = $to;
                }
            }
            unset($field);

            DB::table('cms_pages')->where('id', $page->id)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
