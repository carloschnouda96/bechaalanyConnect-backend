<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces yesterday's `import_excluded` / `supplier_available` /
 * `ignore_supplier_availability` trio with a single ownership rule, because the
 * trio was a patch on the real bug: `is_active` had two writers (the admin AND
 * the hourly supplier sync), so no admin decision could ever reliably survive a
 * sync run, and every flag layered on top made the CMS more confusing without
 * removing the underlying conflict — confirmed live on product #902
 * `clash-of-clan-24`, which the sync had turned off with no way for the admin
 * to turn it back on for good.
 *
 * The fix gives every column exactly one writer:
 *  - `is_active` ("Active") is admin-owned from now on. SupplierCatalogSync
 *    only ever sets it once, when a row is first created; it never writes it
 *    again (see the seed-once rule in SupplierCatalogSync's class docblock).
 *  - `supplier_status` (available / out_of_stock / withdrawn, NULL = not
 *    supplier-managed) is sync-owned and read-only in the CMS — it replaces
 *    `supplier_available`/`ignore_supplier_availability`/`import_excluded`.
 *  - "Sellable" (shown on the storefront) is DERIVED, never stored:
 *    `is_active = 1 AND (supplier_status IS NULL OR supplier_status = 'available')`
 *    — see Product::scopeSellable() / ProductsVariation::scopeSellable().
 *
 * `import_excluded`'s only real job — "keep this off even though the supplier
 * still offers it" — is now simply `is_active = 0`, which finally sticks.
 *
 * Run with --path since the base migrations aren't tracked:
 *   php artisan migrate --path=database/migrations/2026_09_17_000001_supplier_status_replaces_availability_flags.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. New column, both tables ------------------------------------------
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'supplier_status')) {
                $table->string('supplier_status')->nullable()->after('external_id');
            }
        });
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'supplier_status')) {
                $table->string('supplier_status')->nullable()->after('external_qty_values');
            }
        });

        // 2. Backfill from yesterday's columns, in dependency order: variations
        // first (grouped products roll their status up from them), then
        // ungrouped products from their own supplier_available, then grouped
        // products from their now-backfilled variations. Guarded so a fresh
        // environment (no yesterday's columns) skips straight to a no-op.
        if (Schema::hasColumn('products_variations', 'supplier_available')) {
            DB::statement("
                UPDATE products_variations pv
                INNER JOIN products p ON p.id = pv.product_id
                SET pv.supplier_status = CASE
                    WHEN pv.supplier_available = 1 THEN 'available'
                    WHEN pv.supplier_available = 0 THEN 'out_of_stock'
                    ELSE 'withdrawn'
                END
                WHERE p.external_source IS NOT NULL
            ");
        }

        if (Schema::hasColumn('products', 'supplier_available')) {
            DB::statement("
                UPDATE products
                SET supplier_status = CASE
                    WHEN supplier_available = 1 THEN 'available'
                    WHEN supplier_available = 0 THEN 'out_of_stock'
                    ELSE 'withdrawn'
                END
                WHERE external_source IS NOT NULL
                  AND external_id NOT LIKE 'group:%'
            ");

            // Grouped product: roll up from its variations (already backfilled
            // above) — available if any size is, else out_of_stock if any size
            // is, else withdrawn. Matches reconcileGroupedCategories()'s
            // "the product follows its variations" rule.
            DB::statement("
                UPDATE products p
                SET p.supplier_status = (
                    SELECT CASE
                        WHEN SUM(pv.supplier_status = 'available') > 0 THEN 'available'
                        WHEN SUM(pv.supplier_status = 'out_of_stock') > 0 THEN 'out_of_stock'
                        ELSE 'withdrawn'
                    END
                    FROM products_variations pv
                    WHERE pv.product_id = p.id
                )
                WHERE p.external_source IS NOT NULL
                  AND p.external_id LIKE 'group:%'
                  AND EXISTS (SELECT 1 FROM products_variations pv WHERE pv.product_id = p.id)
            ");
        }

        // 3. is_active becomes admin-owned: every supplier row that is not
        // import_excluded is switched ON (the sync could never have kept a row
        // off any other way before this change — see the class docblock), and
        // every import_excluded product (+ its variations) is switched OFF,
        // which is the one admin intent import_excluded ever actually served.
        if (Schema::hasColumn('products', 'import_excluded')) {
            DB::statement("
                UPDATE products
                SET is_active = 1
                WHERE external_source IS NOT NULL AND import_excluded != 1
            ");
            DB::statement("
                UPDATE products
                SET is_active = 0
                WHERE external_source IS NOT NULL AND import_excluded = 1
            ");
            DB::statement("
                UPDATE products_variations pv
                INNER JOIN products p ON p.id = pv.product_id
                SET pv.is_active = 1
                WHERE p.external_source IS NOT NULL AND p.import_excluded != 1
            ");
            DB::statement("
                UPDATE products_variations pv
                INNER JOIN products p ON p.id = pv.product_id
                SET pv.is_active = 0
                WHERE p.external_source IS NOT NULL AND p.import_excluded = 1
            ");
        }

        // 4. CMS field registration: supplier_status replaces the retired trio.
        $this->addField('products', $this->supplierStatusField());
        $this->addField('products-variations', $this->supplierStatusField());

        $this->removeField('products', 'import_excluded');
        $this->removeField('products', 'supplier_available');
        $this->removeField('products', 'ignore_supplier_availability');
        $this->removeField('products-variations', 'supplier_available');
        $this->removeField('products-variations', 'ignore_supplier_availability');

        $this->updateFieldDescription('products', 'is_active', self::IS_ACTIVE_DESCRIPTION);
        $this->updateFieldDescription('products-variations', 'is_active', self::IS_ACTIVE_DESCRIPTION);

        // 5. Drop the retired columns.
        Schema::table('products', function (Blueprint $table) {
            foreach (['import_excluded', 'supplier_available', 'ignore_supplier_availability'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('products_variations', function (Blueprint $table) {
            foreach (['supplier_available', 'ignore_supplier_availability'] as $column) {
                if (Schema::hasColumn('products_variations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Not a true rollback: the trio's original per-row values are gone once
     * dropped. This restores the columns (nullable, so it never blocks) and
     * the old field registrations, but every row starts fresh — down() is a
     * schema rollback, not a data-recovery path.
     */
    public function down(): void
    {
        $this->removeField('products', 'supplier_status');
        $this->removeField('products-variations', 'supplier_status');
        $this->updateFieldDescription('products', 'is_active', null);
        $this->updateFieldDescription('products-variations', 'is_active', null);

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'import_excluded')) {
                $table->boolean('import_excluded')->default(false)->after('profit_percentage');
            }
            if (!Schema::hasColumn('products', 'supplier_available')) {
                $table->boolean('supplier_available')->nullable()->after('import_excluded');
            }
            if (!Schema::hasColumn('products', 'ignore_supplier_availability')) {
                $table->boolean('ignore_supplier_availability')->default(false)->after('supplier_available');
            }
            if (Schema::hasColumn('products', 'supplier_status')) {
                $table->dropColumn('supplier_status');
            }
        });
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'supplier_available')) {
                $table->boolean('supplier_available')->nullable()->after('external_qty_values');
            }
            if (!Schema::hasColumn('products_variations', 'ignore_supplier_availability')) {
                $table->boolean('ignore_supplier_availability')->default(false)->after('supplier_available');
            }
            if (Schema::hasColumn('products_variations', 'supplier_status')) {
                $table->dropColumn('supplier_status');
            }
        });

        $this->addField('products', [
            'name' => 'import_excluded',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Exclude from supplier import — keep this product inactive even when the supplier offers it.',
            'hide_index' => 0, 'hide_create' => 0, 'hide_edit' => 0, 'hide_show' => 0,
            'nullable' => '1', 'unique' => '0',
        ]);
    }

    private const IS_ACTIVE_DESCRIPTION = 'Show on the storefront. This is yours — the supplier sync never changes it. A supplier-sourced product/variation is also visible only when its (read-only) Supplier status is "available" or empty.';

    private function supplierStatusField(): array
    {
        return [
            'name' => 'supplier_status',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier stock status, set by the catalog sync: available / out_of_stock / withdrawn. Empty for a product you manage yourself. Read-only — shown here so you can see why a row is or isn\'t visible; use Active to control visibility.',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];
    }

    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (!in_array($field['name'], array_column($fields, 'name'))) {
            $fields[] = $field;
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }

    private function removeField(string $route, string $name): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== $name));
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    /** Updates one field's `description` in place, leaving every other attribute untouched. */
    private function updateFieldDescription(string $route, string $name, ?string $description): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $changed = false;
        foreach ($fields as &$f) {
            if ($f['name'] === $name) {
                $f['description'] = $description;
                $changed = true;
                break;
            }
        }
        unset($f);
        if ($changed) {
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
