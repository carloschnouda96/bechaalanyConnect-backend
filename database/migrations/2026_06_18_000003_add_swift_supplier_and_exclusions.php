<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-supplier follow-up to the Yassen migrations. The catalog schema is
 * already supplier-agnostic (supplier_categories.source, products.external_source,
 * orders.external_source), so SwiftServices needs no new linkage columns — only:
 *
 *  1. products.import_excluded — the per-product "except Netflix/Shahid/OSN+/
 *     Anghami" switch. When set, SupplierCatalogSync forces the product inactive
 *     and never reactivates it, even while the supplier still offers it.
 *  2. The `import_excluded` checkbox on the products CMS page.
 *  3. The read-only `source` column on the supplier-categories CMS page, so the
 *     admin can tell Yassen vs Swift categories apart when toggling import_enabled.
 *
 * Run with --path since the base migrations aren't tracked:
 *   php artisan migrate --path=database/migrations/2026_06_18_000003_add_swift_supplier_and_exclusions.php
 *
 * Mirrors the field-registration approach in
 * 2026_06_18_000002_register_yassen_fields_and_pages_in_cms.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. products.import_excluded column ----------------------------------
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'import_excluded')) {
                $table->boolean('import_excluded')->default(false)->after('profit_percentage');
            }
        });

        // 2. import_excluded checkbox on the products CMS page ----------------
        $this->addField('products', [
            'name' => 'import_excluded',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Exclude from supplier import — keep this product inactive even when the supplier offers it.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        // 3. read-only `source` column on the supplier-categories CMS page ----
        $this->addField('supplier-categories', [
            'name' => 'source',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier this category comes from, e.g. yassen or swift (read-only).',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
    }

    public function down(): void
    {
        $this->removeField('products', 'import_excluded');
        $this->removeField('supplier-categories', 'source');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'import_excluded')) {
                $table->dropColumn('import_excluded');
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
