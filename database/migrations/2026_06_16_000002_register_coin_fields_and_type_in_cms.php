<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registers the coin fields on the CMS "products-variations" page
     * (`unit_amount` as a plain field, `unit_label` as a translatable field) and
     * adds the "Coin Recharge" product type (id = 4) so it is selectable on the
     * CMS product form. Mirrors the registration approach used by the KYC fields
     * migration.
     */
    private const PAGE_ROUTE = 'products-variations';
    private const COIN_TYPE_ID = 4;
    private const COIN_TYPE_SLUG = 'coin-recharge';

    public function up(): void
    {
        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if ($page) {
            // Plain field: coins per block
            $fields = json_decode($page->fields, true) ?: [];
            if (!in_array('unit_amount', array_column($fields, 'name'))) {
                $fields[] = [
                    'name' => 'unit_amount',
                    'migration_type' => 'integer',
                    'form_field' => 'number',
                    'form_field_additionals_1' => null,
                    'form_field_additionals_2' => null,
                    'description' => 'Coins per block for Coin Recharge products (e.g. 10000). Leave empty for other product types.',
                    'hide_index' => 1,
                    'hide_create' => 0,
                    'hide_edit' => 0,
                    'hide_show' => 0,
                    'nullable' => '1',
                    'unique' => '0',
                ];
            }

            // Translatable field: unit label (e.g. "Coins")
            $translatable = json_decode($page->translatable_fields, true) ?: [];
            if (!in_array('unit_label', array_column($translatable, 'name'))) {
                $translatable[] = [
                    'name' => 'unit_label',
                    'migration_type' => 'string',
                    'form_field' => 'text',
                    'description' => null,
                    'hide_index' => 1,
                    'hide_create' => 0,
                    'hide_edit' => 0,
                    'hide_show' => 0,
                    'nullable' => '1',
                ];
            }

            DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
                'fields' => json_encode($fields),
                'translatable_fields' => json_encode($translatable),
                'updated_at' => now(),
            ]);
        }

        // Add the Coin Recharge product type
        if (!DB::table('product_type')->where('id', self::COIN_TYPE_ID)->exists()) {
            DB::table('product_type')->insert([
                'id' => self::COIN_TYPE_ID,
                'cms_draft_flag' => 0,
                'slug' => self::COIN_TYPE_SLUG,
                'title' => 'Coin Recharge',
                'ht_pos' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $page = DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->first();
        if ($page) {
            $fields = json_decode($page->fields, true) ?: [];
            $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== 'unit_amount'));

            $translatable = json_decode($page->translatable_fields, true) ?: [];
            $translatable = array_values(array_filter($translatable, fn ($f) => $f['name'] !== 'unit_label'));

            DB::table('cms_pages')->where('route', self::PAGE_ROUTE)->update([
                'fields' => json_encode($fields),
                'translatable_fields' => json_encode($translatable),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_type')
            ->where('id', self::COIN_TYPE_ID)
            ->where('slug', self::COIN_TYPE_SLUG)
            ->delete();
    }
};
