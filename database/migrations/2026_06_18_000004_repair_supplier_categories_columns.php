<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the `supplier_categories` table after the hellotree CMS rebuilt it from
 * the supplier-categories page field list, which dropped every column not
 * registered as a CMS field — `source`, `parent_external_id`, `image`,
 * `category_id`, `subcategory_id` — leaving only name/external_id/import_enabled.
 * The supplier sync needs those columns back (it errored with
 * "Unknown column 'source'"). Existing rows are all Yassen categories, so the
 * `source` default of 'yassen' tags them correctly; new Swift rows insert with
 * source='swift' explicitly.
 *
 * Also restores the intended composite unique (source, external_id) — the rebuild
 * left a same-named unique on external_id alone.
 *
 * Run with --path since the base migrations aren't tracked:
 *   php artisan migrate --path=database/migrations/2026_06_18_000004_repair_supplier_categories_columns.php
 */
return new class extends Migration
{
    private const UNIQUE = 'supplier_categories_source_external_unique';

    public function up(): void
    {
        Schema::table('supplier_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_categories', 'source')) {
                $table->string('source')->default('yassen')->after('cms_draft_flag');
            }
            if (!Schema::hasColumn('supplier_categories', 'parent_external_id')) {
                $table->string('parent_external_id')->nullable()->after('external_id');
            }
            if (!Schema::hasColumn('supplier_categories', 'image')) {
                $table->string('image')->nullable()->after('name');
            }
            if (!Schema::hasColumn('supplier_categories', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('import_enabled');
            }
            if (!Schema::hasColumn('supplier_categories', 'subcategory_id')) {
                $table->unsignedBigInteger('subcategory_id')->nullable()->after('category_id');
            }
        });

        // Replace any existing single-column unique with the composite (source, external_id).
        if ($this->indexExists(self::UNIQUE)) {
            Schema::table('supplier_categories', fn (Blueprint $t) => $t->dropIndex(self::UNIQUE));
        }
        if (!$this->indexExists(self::UNIQUE)) {
            Schema::table('supplier_categories', fn (Blueprint $t) => $t->unique(['source', 'external_id'], self::UNIQUE));
        }
    }

    public function down(): void
    {
        if ($this->indexExists(self::UNIQUE)) {
            Schema::table('supplier_categories', fn (Blueprint $t) => $t->dropIndex(self::UNIQUE));
        }

        Schema::table('supplier_categories', function (Blueprint $table) {
            foreach (['parent_external_id', 'image', 'category_id', 'subcategory_id', 'source'] as $col) {
                if (Schema::hasColumn('supplier_categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function indexExists(string $name): bool
    {
        return count(DB::select("SHOW INDEX FROM `supplier_categories` WHERE Key_name = ?", [$name])) > 0;
    }
};
