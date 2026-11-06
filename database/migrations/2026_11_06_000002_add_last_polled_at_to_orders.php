<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order polling takes a limited number of pending rows, and it took the first N, so
 * long-running or stuck orders could keep every other customer's order from being
 * checked. It now goes least recently checked first. Registered in cms_pages.fields
 * because a CMS page save drops any column missing from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'last_polled_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('last_polled_at')->nullable();
            });
        }

        $page = DB::table('cms_pages')->where('route', 'orders')->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (!in_array('last_polled_at', array_column($fields, 'name'), true)) {
            $fields[] = [
                'name' => 'last_polled_at',
                'migration_type' => 'timestamp',
                'form_field' => 'text',
                'description' => 'When the supplier was last asked about this order. Read-only.',
                'hide_index' => 1,
                'hide_create' => 1,
                'hide_edit' => 1,
                'form_field_additionals_1' => null,
                'form_field_additionals_2' => null,
                'hide_show' => 0,
                'nullable' => '1',
                'unique' => '0',
            ];
            DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'last_polled_at')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('last_polled_at'));
        }

        $page = DB::table('cms_pages')->where('route', 'orders')->first();
        if ($page) {
            $fields = array_values(array_filter(json_decode($page->fields, true) ?: [], fn ($f) => $f['name'] !== 'last_polled_at'));
            DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields)]);
        }
    }
};
