<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second supplier (U-Manage) also quotes in Lebanese Pounds, so the LBP→USD
 * divisor stops being a usharez detail and becomes a platform setting. This adds
 * the shared `fixed_settings.lbp_per_usd`, read by every LBP supplier via
 * App\Services\Suppliers\ExchangeRate.
 *
 * `usharez_lbp_per_usd` (migration 2026_07_28_000001) is deliberately kept as a
 * per-supplier override that still wins, so nothing about the shipped usharez
 * pricing changes. Leave it empty to follow the shared rate.
 *
 * Registering the column as a CMS field also protects it: the hellotree CMS
 * rebuilds a page's table from its registered `fields` on schema-save and
 * silently drops anything unregistered (see 2026_06_18_000004).
 *
 * The existing usharez value is copied across as the initial shared rate so the
 * two don't silently diverge on day one.
 */
return new class extends Migration
{
    private const COLUMN = 'lbp_per_usd';

    public function up(): void
    {
        Schema::table('fixed_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('fixed_settings', self::COLUMN)) {
                $table->decimal(self::COLUMN, 20, 4)->nullable()->after('default_profit_percentage');
            }
        });

        // Seed the shared rate from whatever usharez was already using.
        if (Schema::hasColumn('fixed_settings', 'usharez_lbp_per_usd')) {
            DB::table('fixed_settings')
                ->whereNull(self::COLUMN)
                ->whereNotNull('usharez_lbp_per_usd')
                ->update([self::COLUMN => DB::raw('usharez_lbp_per_usd')]);
        }

        $this->addField('fixed-settings', [
            'name' => self::COLUMN,
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Lebanese Pounds per 1 USD. Used to convert supplier prices that arrive in LBP (usharez, U-Manage) into USD costs. Leave empty to use the server config value. Changing this re-prices every LBP-quoted supplier catalog on the next sync.',
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
