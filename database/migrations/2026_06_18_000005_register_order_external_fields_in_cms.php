<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces the supplier-order fields on the "orders" CMS page so the admin can
 * see each order's supplier status at a glance (pending / completed / failed)
 * and the supplier order id — read-only, since they're set by the sync/poll jobs.
 *
 * Also protects these columns from the hellotree CMS schema-save behaviour, which
 * drops any `orders` table column not registered as a CMS field (see the
 * supplier_categories gotcha in CLAUDE.md).
 *
 * Mirrors the addField/removeField helpers in
 * 2026_06_18_000002_register_yassen_fields_and_pages_in_cms.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addField('orders', [
            'name' => 'external_status',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier order status (read-only): pending / completed / failed. Updated automatically by the supplier order poll.',
            'hide_index' => 0,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->addField('orders', [
            'name' => 'external_order_id',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier order id (read-only), returned when the order was placed at the supplier.',
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
        $this->removeField('orders', 'external_status');
        $this->removeField('orders', 'external_order_id');
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
