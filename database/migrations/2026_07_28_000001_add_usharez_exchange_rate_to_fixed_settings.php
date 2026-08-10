<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The usharez quota API prices in LBP (11 GB = 660,000) while the storefront is
 * USD-only, so UsharezConnector divides supplier costs by an LBP→USD rate. That
 * rate moves, so it lives in Fixed Settings where an admin can update it from the
 * CMS without a deploy; `services.usharez.lbp_per_usd` (USHAREZ_LBP_PER_USD) is
 * the fallback when the column is empty.
 *
 * Registering it as a CMS field is also what protects the column: the hellotree
 * CMS rebuilds a page's table from its registered `fields` on schema-save and
 * silently DROPS anything not registered — the same trap documented in
 * 2026_06_18_000004_repair_supplier_categories_columns.
 *
 * Mirrors the field-registration approach in
 * 2026_06_18_000002_register_yassen_fields_and_pages_in_cms.php.
 */
return new class extends Migration
{
    private const COLUMN = 'usharez_lbp_per_usd';

    public function up(): void
    {
        Schema::table('fixed_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('fixed_settings', self::COLUMN)) {
                $table->decimal(self::COLUMN, 20, 4)->nullable()->after('default_profit_percentage');
            }
        });

        $this->addField('fixed-settings', [
            'name' => self::COLUMN,
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Lebanese Pounds per 1 USD, used to convert usharez supplier prices (which arrive in LBP) into USD costs. Leave empty to use the USHAREZ_LBP_PER_USD value from the server config. Changing this re-prices the whole usharez catalog on the next sync.',
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
        $this->removeField('fixed-settings', self::COLUMN);

        Schema::table('fixed_settings', function (Blueprint $table) {
            if (Schema::hasColumn('fixed_settings', self::COLUMN)) {
                $table->dropColumn(self::COLUMN);
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
