<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-set maximum order size per variation, the counterpart of
     * min_quantity and in the same unit (coins for a Coin Recharge variation,
     * plain quantity otherwise). ProductsVariation::maxOrderQuantity() takes the
     * smallest of this, the supplier's own maximum and our hard cap.
     *
     * Registered on the CMS page too — an unregistered column is dropped by the
     * next CMS schema save.
     */
    private const PAGE_ROUTE = 'products-variations';

    public function up(): void
    {
        if (!Schema::hasColumn('products_variations', 'max_quantity')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->integer('max_quantity')->nullable()->after('min_quantity');
            });
        }

        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (in_array('max_quantity', array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = [
            'name' => 'max_quantity',
            'migration_type' => 'integer',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Maximum the customer can order in one go: coins for Coin Recharge products (e.g. 500000), quantity otherwise. Leave empty for no maximum.',
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
            $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== 'max_quantity'));
            DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('products_variations', 'max_quantity')) {
            Schema::table('products_variations', function (Blueprint $table) {
                $table->dropColumn('max_quantity');
            });
        }
    }
};
