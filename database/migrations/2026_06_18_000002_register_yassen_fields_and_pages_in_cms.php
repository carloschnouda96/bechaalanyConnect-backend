<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wires the Yassen integration into the hellotree CMS:
 *
 *  1. Adds the editable `profit_percentage` field to the "products" CMS form so
 *     admins set per-product markup.
 *  2. Adds `default_profit_percentage` to the "fixed-settings" form (global
 *     fallback used for products with no per-product override).
 *  3. Registers a new "supplier-categories" CMS page exposing the per-category
 *     `import_enabled` toggle (rows are created/refreshed by `yassen:sync`; the
 *     CMS package auto-routes any cms_pages row with custom_page = 0).
 *
 * Mirrors the field-registration approach in
 * 2026_06_16_000002_register_coin_fields_and_type_in_cms.php.
 */
return new class extends Migration
{
    private const SUPPLIER_ROUTE = 'supplier-categories';

    public function up(): void
    {
        // 1. profit_percentage on the products page ---------------------------
        $this->addField('products', [
            'name' => 'profit_percentage',
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Markup % applied to the supplier cost for this product (e.g. 50 sells a $1 item for $1.50). Leave empty to use the global default in Fixed Settings.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        // 2. default_profit_percentage on fixed-settings ----------------------
        $this->addField('fixed-settings', [
            'name' => 'default_profit_percentage',
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Default markup % applied to supplier products that have no per-product profit set.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        // 3. supplier-categories CMS page -------------------------------------
        if (!DB::table('cms_pages')->where('route', self::SUPPLIER_ROUTE)->exists()) {
            $fields = [
                [
                    'name' => 'name', 'migration_type' => 'string', 'form_field' => 'text',
                    'form_field_additionals_1' => null, 'form_field_additionals_2' => null,
                    'description' => 'Supplier category name (read-only, set by sync).',
                    'hide_index' => 0, 'hide_create' => 1, 'hide_edit' => 1, 'hide_show' => 0,
                    'nullable' => '1', 'unique' => '0',
                ],
                [
                    'name' => 'external_id', 'migration_type' => 'string', 'form_field' => 'text',
                    'form_field_additionals_1' => null, 'form_field_additionals_2' => null,
                    'description' => 'Supplier category id (read-only).',
                    'hide_index' => 0, 'hide_create' => 1, 'hide_edit' => 1, 'hide_show' => 0,
                    'nullable' => '1', 'unique' => '0',
                ],
                [
                    'name' => 'import_enabled', 'migration_type' => 'boolean', 'form_field' => 'checkbox',
                    'form_field_additionals_1' => null, 'form_field_additionals_2' => null,
                    'description' => 'Enable to import this category\'s products into the catalog on the next sync.',
                    'hide_index' => 0, 'hide_create' => 1, 'hide_edit' => 0, 'hide_show' => 0,
                    'nullable' => '1', 'unique' => '0',
                ],
            ];

            DB::table('cms_pages')->insert([
                'icon' => 'fa fa-cloud-download',
                'display_name' => 'Supplier Category',
                'display_name_plural' => 'Supplier Categories',
                'database_table' => 'supplier_categories',
                'route' => self::SUPPLIER_ROUTE,
                'model_name' => 'SupplierCategory',
                'order_display' => 'name',
                'preview_path' => null,
                'fields' => json_encode($fields),
                'translatable_fields' => json_encode([]),
                // Rows are managed by the sync job: allow edit (toggle) + show, no manual add/delete.
                'add' => 0,
                'edit' => 1,
                'delete' => 0,
                'show' => 1,
                'single_record' => 0,
                'apis' => 0,
                'server_side_pagination' => 0,
                'with_export' => 0,
                'hidden' => 0,
                'custom_page' => 0,
                'parent_title' => null,
                'parent_icon' => null,
                'ht_pos' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $this->removeField('products', 'profit_percentage');
        $this->removeField('fixed-settings', 'default_profit_percentage');

        DB::table('cms_pages')->where('route', self::SUPPLIER_ROUTE)->delete();
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
