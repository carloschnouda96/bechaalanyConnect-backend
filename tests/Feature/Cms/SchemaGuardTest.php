<?php

namespace Tests\Feature\Cms;

use App\Services\Cms\SchemaGuard;
use App\Services\Cms\SchemaManifest;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The regression net for the highest-consequence failure mode in this codebase.
 *
 * Saving a CMS page schema rewrites the table it manages: it drops columns that are
 * not registered in cms_pages.fields, re-asserts every registered column's type from
 * an argument-less Blueprint call, and forces every column NULLable. That already
 * destroyed `supplier_categories` once in production.
 *
 * These tests assert (a) that the columns which must never be dropped are in fact
 * registered, and (b) that SchemaGuard puts back what the type loop clobbers.
 *
 * NOTE: DDL is not transactional in MySQL, so the tests that mutate the schema
 * restore it themselves rather than relying on DatabaseTransactions.
 */
class SchemaGuardTest extends TestCase
{
    /**
     * Columns that exist in the database and would be silently dropped by a CMS page
     * save if they were ever removed from cms_pages.fields.
     *
     * orders.external_order_uuid is the one that matters most: it is the ONLY
     * re-entry guard in SupplierOrderFulfillment::fulfill(), so losing it lets a
     * retried job place — and pay for — the same supplier order twice.
     */
    public static function protectedColumnProvider(): array
    {
        return [
            'orders.external_source' => ['orders', 'external_source'],
            'orders.external_order_uuid' => ['orders', 'external_order_uuid'],
            'orders.external_response' => ['orders', 'external_response'],
            'products.external_source' => ['products', 'external_source'],
            'products.external_id' => ['products', 'external_id'],
            'products_variations.external_id' => ['products_variations', 'external_id'],
            'products_variations.external_price' => ['products_variations', 'external_price'],
            'products_variations.external_type' => ['products_variations', 'external_type'],
            'products_variations.external_qty_values' => ['products_variations', 'external_qty_values'],
            'supplier_categories.parent_external_id' => ['supplier_categories', 'parent_external_id'],
            'supplier_categories.image' => ['supplier_categories', 'image'],
            'supplier_categories.category_id' => ['supplier_categories', 'category_id'],
            'supplier_categories.subcategory_id' => ['supplier_categories', 'subcategory_id'],
            // Losing this silently un-groups the category: the next sync goes back to
            // one product per supplier row, scattering a single product's amount
            // dropdown across the storefront as separate products again.
            'supplier_categories.group_as_single_product' => ['supplier_categories', 'group_as_single_product'],
        ];
    }

    /**
     * @dataProvider protectedColumnProvider
     */
    public function test_supplier_column_is_registered_as_a_cms_field(string $table, string $column): void
    {
        $route = $this->routeForTable($table);
        $this->assertNotNull($route, "No cms_pages row manages `{$table}`.");

        $fields = json_decode(
            DB::table('cms_pages')->where('route', $route)->value('fields') ?: '[]',
            true
        ) ?: [];

        $this->assertContains(
            $column,
            array_column($fields, 'name'),
            "`{$table}`.`{$column}` is not registered in cms_pages.fields, so the next CMS "
            . 'page save will DROP it (CmsPagesController::editDatabase drops any column '
            . 'missing from the submitted field list).'
        );
    }

    /**
     * Reproduces exactly what editDatabase()'s type loop does to a high-precision
     * column, then asserts the guard undoes it.
     */
    public function test_guard_restores_precision_the_cms_type_loop_destroys(): void
    {
        $column = 'external_price';
        $table = 'products_variations';

        $this->assertSame('decimal(20,8)', $this->columnType($table, $column), 'precondition');

        // Back the values up: narrowing to (8,2) rounds them irreversibly, and this
        // test must not damage the database it runs against.
        DB::statement('DROP TABLE IF EXISTS zz_schemaguard_test_backup');
        DB::statement("CREATE TABLE zz_schemaguard_test_backup AS SELECT id, {$column} FROM {$table}");

        try {
            // $table->decimal('external_price')->nullable()->change()
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} DECIMAL(8,2) NULL");
            $this->assertSame('decimal(8,2)', $this->columnType($table, $column), 'simulated CMS save should have narrowed the column');

            $result = app(SchemaGuard::class)->repair($table);

            $this->assertNotEmpty($result['applied'], 'guard should have detected and repaired the drift');
            $this->assertEmpty($result['unrepairable']);
            $this->assertSame('decimal(20,8)', $this->columnType($table, $column));
        } finally {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} DECIMAL(20,8) NULL");
            DB::statement("UPDATE {$table} t JOIN zz_schemaguard_test_backup b ON b.id = t.id SET t.{$column} = b.{$column}");
            DB::statement('DROP TABLE zz_schemaguard_test_backup');
        }
    }

    /**
     * Every column the CMS forces NULLable must be restored to NOT NULL.
     */
    public function test_guard_restores_not_null(): void
    {
        $table = 'supplier_categories';
        $column = 'source';

        $this->assertFalse($this->columnIsNullable($table, $column), 'precondition');

        try {
            // The vendor emits ->nullable() for every column regardless of the flag
            // (CmsPagesController.php:388 — both ternary branches are 'nullable').
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR(191) NULL DEFAULT 'yassen'");
            $this->assertTrue($this->columnIsNullable($table, $column));

            app(SchemaGuard::class)->repair($table);

            $this->assertFalse(
                $this->columnIsNullable($table, $column),
                'guard should have restored NOT NULL'
            );
        } finally {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR(191) NOT NULL DEFAULT 'yassen'");
        }
    }

    /**
     * A dropped index must be recreated. supplier_categories lost its composite
     * unique exactly this way in production.
     */
    public function test_guard_recreates_a_dropped_index(): void
    {
        $table = 'supplier_categories';
        $index = 'supplier_categories_source_external_unique';

        $this->assertTrue($this->indexExists($table, $index), 'precondition');

        try {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
            $this->assertFalse($this->indexExists($table, $index));

            app(SchemaGuard::class)->repair($table);

            $this->assertTrue($this->indexExists($table, $index), 'guard should have recreated the unique index');
        } finally {
            if (!$this->indexExists($table, $index)) {
                DB::statement("ALTER TABLE {$table} ADD UNIQUE {$index} (source, external_id)");
            }
        }
    }

    /**
     * The steady state: with no interference, the guard must be a no-op. If this
     * fails, the manifest and the real schema have drifted apart.
     */
    public function test_guard_reports_no_drift_against_the_current_schema(): void
    {
        $result = app(SchemaGuard::class)->repair(null, true);

        $this->assertSame([], $result['applied'], "Schema drifted from the manifest:\n" . implode("\n", $result['applied']));
        $this->assertSame([], $result['unrepairable'], implode("\n", $result['unrepairable']));
    }

    public function test_manifest_only_references_tables_that_exist(): void
    {
        foreach (SchemaManifest::tableNames() as $table) {
            $this->assertNotNull(
                DB::selectOne(
                    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table]
                ),
                "Manifest references `{$table}`, which does not exist."
            );
        }
    }

    private function routeForTable(string $table): ?string
    {
        return DB::table('cms_pages')->where('database_table', $table)->value('route');
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->c;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT IS_NULLABLE n FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->n === 'YES';
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
}
