<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-set minimum order size per variation, entered in what the customer
     * sees: coins for a Coin Recharge variation (unit_amount set), plain quantity
     * otherwise. ProductsVariation::minOrderQuantity() turns it into blocks and
     * combines it with the supplier's own minimum (external_qty_values.min).
     *
     * Registered on the CMS page too — an unregistered column is dropped by the
     * next CMS schema save.
     */
    private const PAGE_ROUTE = 'products-variations';

    public function up(): void
    {
        if (!Schema::hasColumn('products_variations', 'min_quantity')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->integer('min_quantity')->nullable()->after('unit_amount');
            });
        }

        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (in_array('min_quantity', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'min_quantity',
            'migration_type' => 'integer',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Minimum the customer can order: coins for Coin Recharge products (e.g. 10000), quantity otherwise. Leave empty for no minimum.',
            'hide_index' => 1,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];

        DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if ($page) {
            $fields = json_decode($page->fields, true) ?: [];
            $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== 'min_quantity'));
            DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('products_variations', 'min_quantity')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->dropColumn('min_quantity');
            });
        }
    }
};
