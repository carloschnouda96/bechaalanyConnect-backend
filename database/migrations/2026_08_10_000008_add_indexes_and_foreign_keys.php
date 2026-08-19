<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the storefront and CMS actually run, plus the foreign keys
 * that were never declared.
 *
 * INDEXES — cross-referenced against real controller queries:
 *
 *   ProductController::index/SingleProduct filter on products.is_active and walk
 *   whereHas('subcategory.category'), a correlated EXISTS on products.subcategory_id —
 *   which had NO index at all, making it the most expensive query in the app.
 *
 *   CategoryController::index does where(is_active)->orderBy(ht_pos) against a
 *   `categories` table that had ZERO secondary indexes: a full scan plus a filesort
 *   on every homepage and category page load.
 *
 *   OrderController::getUserOrders and CreditsController::getUserCredits both do
 *   where(users_id)->orderBy(created_at)->paginate(). users_id was indexed alone, so
 *   every page cost a filesort; the composite covers both.
 *
 *   Cms\OrdersController::calculateTotalProfit filters statuses_id and joins on
 *   product_variation_id — neither was indexed, so the admin orders page drove a full
 *   table scan of `orders` on every render.
 *
 * FOREIGN KEYS — all ON DELETE RESTRICT, deliberately.
 *
 *   Every FK that already existed in this schema is ON DELETE CASCADE, which is how
 *   deleting one user hard-erases their entire order and top-up history. The new keys
 *   refuse the delete instead. Combined with the soft deletes added in the next
 *   migration, the normal CMS "Delete" button keeps working (it becomes an UPDATE)
 *   while an actual hard delete of referenced data is blocked.
 *
 *   supplier_categories.category_id/subcategory_id use SET NULL rather than RESTRICT:
 *   they are an optional admin mapping override, so removing a site category should
 *   clear the mapping and let the sync re-resolve, not block the delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexes();
        $this->alignSupplierCategoryTypes();
        $this->addForeignKeys();
    }

    public function down(): void
    {
        foreach ($this->foreignKeys() as [$table, $column, , , $name]) {
            if ($this->foreignKeyExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }
        }

        foreach ($this->indexes() as [$table, $name]) {
            if ($this->indexExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    /** @return array<int, array{0:string,1:string,2:string,3:bool}> table, index name, columns, unique */
    private function indexes(): array
    {
        return [
            // --- storefront browse path -------------------------------------
            ['products', 'products_active_subcategory_pos_index', '`is_active`, `subcategory_id`, `ht_pos`', false],
            ['products', 'products_slug_unique', '`slug`', true],
            ['categories', 'categories_active_pos_index', '`is_active`, `ht_pos`', false],
            ['categories', 'categories_slug_unique', '`slug`', true],
            ['subcategories', 'subcategories_active_category_pos_index', '`is_active`, `category_id`, `ht_pos`', false],
            ['subcategories', 'subcategories_slug_unique', '`slug`', true],
            ['products_variations', 'products_variations_product_active_index', '`product_id`, `is_active`', false],

            // --- account dashboard ------------------------------------------
            ['orders', 'orders_user_created_index', '`users_id`, `created_at`', false],
            ['credits_transfer', 'credits_transfer_user_created_index', '`users_id`, `created_at`', false],
            ['user_notifications', 'user_notifications_user_read_index', '`users_id`, `read_at`', false],

            // --- CMS / admin --------------------------------------------------
            ['orders', 'orders_statuses_id_index', '`statuses_id`', false],
            ['orders', 'orders_product_variation_id_index', '`product_variation_id`', false],
            ['credits_transfer', 'credits_transfer_statuses_id_index', '`statuses_id`', false],
            ['users', 'users_verification_statuses_id_index', '`verification_statuses_id`', false],
            ['users', 'users_user_types_id_index', '`user_types_id`', false],

            // --- supplier sync -------------------------------------------------
            ['supplier_categories', 'supplier_categories_source_enabled_index', '`source`, `import_enabled`', false],

            // --- lookup slugs used as public keys --------------------------------
            ['credits_types', 'credits_types_slug_unique', '`slug`', true],
            ['product_type', 'product_type_slug_unique', '`slug`', true],
            ['user_types', 'user_types_slug_unique', '`slug`', true],
            ['seo_pages', 'seo_pages_slug_unique', '`slug`', true],
        ];
    }

    /** @return array<int, array{0:string,1:string,2:string,3:string,4:string}> table, column, parent table, on-delete, fk name */
    private function foreignKeys(): array
    {
        return [
            ['orders', 'product_variation_id', 'products_variations', 'RESTRICT', 'orders_product_variation_id_foreign'],
            ['orders', 'statuses_id', 'statuses', 'RESTRICT', 'orders_statuses_id_foreign'],
            ['credits_transfer', 'statuses_id', 'statuses', 'RESTRICT', 'credits_transfer_statuses_id_foreign'],
            ['products', 'subcategory_id', 'subcategories', 'RESTRICT', 'products_subcategory_id_foreign'],
            ['products', 'product_type_id', 'product_type', 'RESTRICT', 'products_product_type_id_foreign'],
            ['users', 'user_types_id', 'user_types', 'RESTRICT', 'users_user_types_id_foreign'],
            ['users', 'verification_statuses_id', 'verification_statuses', 'RESTRICT', 'users_verification_statuses_id_foreign'],
            ['supplier_categories', 'category_id', 'categories', 'SET NULL', 'supplier_categories_category_id_foreign'],
            ['supplier_categories', 'subcategory_id', 'subcategories', 'SET NULL', 'supplier_categories_subcategory_id_foreign'],
        ];
    }

    private function addIndexes(): void
    {
        foreach ($this->indexes() as [$table, $name, $columns, $unique]) {
            if (!Schema::hasTable($table) || $this->indexExists($table, $name)) {
                continue;
            }

            if ($unique && $this->hasDuplicates($table, trim(str_replace('`', '', $columns)))) {
                // Fail loudly rather than half-applying the migration. A duplicate
                // slug is a data problem someone has to look at.
                throw new RuntimeException(
                    "Cannot add unique index {$name}: `{$table}` contains duplicate values. Resolve them and re-run."
                );
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD %s `%s` (%s)',
                $table,
                $unique ? 'UNIQUE' : 'INDEX',
                $name,
                $columns
            ));
        }
    }

    /**
     * supplier_categories.category_id / subcategory_id are bigint unsigned while
     * categories.id / subcategories.id are int unsigned. MySQL refuses a foreign key
     * across mismatched integer widths, which is almost certainly why these were never
     * declared. Values are small, so narrowing is lossless.
     */
    private function alignSupplierCategoryTypes(): void
    {
        foreach (['category_id', 'subcategory_id'] as $column) {
            if (!Schema::hasColumn('supplier_categories', $column)) {
                continue;
            }

            $type = DB::selectOne(
                'SELECT COLUMN_TYPE c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['supplier_categories', $column]
            )?->c;

            if ($type !== 'int unsigned') {
                DB::statement("ALTER TABLE `supplier_categories` MODIFY COLUMN `{$column}` INT UNSIGNED NULL");
            }
        }
    }

    private function addForeignKeys(): void
    {
        foreach ($this->foreignKeys() as [$table, $column, $parent, $onDelete, $name]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column) || $this->foreignKeyExists($table, $name)) {
                continue;
            }

            $orphans = DB::selectOne(
                "SELECT COUNT(*) c FROM `{$table}` t
                   LEFT JOIN `{$parent}` p ON p.id = t.`{$column}`
                  WHERE t.`{$column}` IS NOT NULL AND p.id IS NULL"
            )->c;

            if ($orphans > 0) {
                throw new RuntimeException(
                    "Cannot add {$name}: `{$table}`.`{$column}` has {$orphans} row(s) pointing at a missing `{$parent}`. "
                    . 'Clean them up and re-run.'
                );
            }

            // SET NULL requires the column to be nullable.
            if ($onDelete === 'SET NULL') {
                $nullable = DB::selectOne(
                    'SELECT IS_NULLABLE n FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )?->n;

                if ($nullable !== 'YES') {
                    continue;
                }
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s ON UPDATE RESTRICT',
                $table,
                $name,
                $column,
                $parent,
                $onDelete
            ));
        }
    }

    private function hasDuplicates(string $table, string $columns): bool
    {
        return (bool) DB::selectOne(
            "SELECT COUNT(*) c FROM (SELECT {$columns} FROM `{$table}` GROUP BY {$columns} HAVING COUNT(*) > 1) x"
        )->c;
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "FOREIGN KEY" LIMIT 1',
            [$table, $name]
        ) !== null;
    }
};
