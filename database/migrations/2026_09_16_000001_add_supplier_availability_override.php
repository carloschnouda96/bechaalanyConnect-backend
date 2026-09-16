<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes: an admin ticking a supplier-imported product/variation active in the
 * CMS was silently reverted by the next hourly sync, because
 * SupplierCatalogSync::isActiveAfterImport() had only one input besides
 * import_excluded — the feed's own `available` flag — so there was no way for
 * an admin decision to outlive the next cron run.
 *
 * Two columns, on both `products` and `products_variations` (an override can be
 * per-variation, e.g. one size back in stock, or per-product for a grouped
 * category's whole dropdown):
 *
 *  - `supplier_available` — read-only record of the feed's last `available`
 *    value for this row. NULL until the first sync touches it. Kept visible
 *    (not in $extraHidden) so the storefront can show an out-of-stock notice.
 *  - `ignore_supplier_availability` — the admin's force-on switch. Combined
 *    with import_excluded in SupplierCatalogSync::isActiveAfterImport():
 *    excluded still beats everything; the override beats "not offered"; a row
 *    dropped from the feed entirely is still deactivated regardless (nothing
 *    to fulfil an order against).
 *
 * Run with --path since the base migrations aren't tracked:
 *   php artisan migrate --path=database/migrations/2026_09_16_000001_add_supplier_availability_override.php
 *
 * Mirrors the field-registration approach in
 * 2026_06_18_000003_add_swift_supplier_and_exclusions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'supplier_available')) {
                $table->boolean('supplier_available')->nullable()->after('import_excluded');
            }
            if (!Schema::hasColumn('products', 'ignore_supplier_availability')) {
                $table->boolean('ignore_supplier_availability')->default(false)->after('supplier_available');
            }
        });

        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'supplier_available')) {
                $table->boolean('supplier_available')->nullable()->after('external_qty_values');
            }
            if (!Schema::hasColumn('products_variations', 'ignore_supplier_availability')) {
                $table->boolean('ignore_supplier_availability')->default(false)->after('supplier_available');
            }
        });

        $this->addField('products', [
            'name' => 'supplier_available',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Last availability reported by the supplier (managed by the sync — read-only).',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
        $this->addField('products', [
            'name' => 'ignore_supplier_availability',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => "Keep this active even when the supplier reports it unavailable. Orders may still be rejected by the supplier and auto-refunded.",
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->addField('products-variations', [
            'name' => 'supplier_available',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Last availability reported by the supplier (managed by the sync — read-only).',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
        $this->addField('products-variations', [
            'name' => 'ignore_supplier_availability',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => "Keep this active even when the supplier reports it unavailable. Orders may still be rejected by the supplier and auto-refunded.",
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
    }

    public function down(): void
    {
        $this->removeField('products', 'supplier_available');
        $this->removeField('products', 'ignore_supplier_availability');
        $this->removeField('products-variations', 'supplier_available');
        $this->removeField('products-variations', 'ignore_supplier_availability');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'ignore_supplier_availability')) {
                $table->dropColumn('ignore_supplier_availability');
            }
            if (Schema::hasColumn('products', 'supplier_available')) {
                $table->dropColumn('supplier_available');
            }
        });
        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'ignore_supplier_availability')) {
                $table->dropColumn('ignore_supplier_availability');
            }
            if (Schema::hasColumn('products_variations', 'supplier_available')) {
                $table->dropColumn('supplier_available');
            }
        });
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
};
