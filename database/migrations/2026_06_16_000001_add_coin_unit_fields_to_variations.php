<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the per-block coin/unit fields used by "Coin / Unit based" products
     * (product_type_id = 4). `unit_amount` is the number of coins per block
     * (e.g. 10000) and lives on the variation; `unit_label` is the translatable
     * unit name (e.g. "Coins" / "كوينز"). The per-block price is the existing
     * products_variations.price column.
     */
    public function up(): void
    {
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'unit_amount')) {
                $table->unsignedInteger('unit_amount')->nullable()->after('cost_price');
            }
        });

        Schema::table('products_variations_translations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations_translations', 'unit_label')) {
                $table->string('unit_label')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'unit_amount')) {
                $table->dropColumn('unit_amount');
            }
        });

        Schema::table('products_variations_translations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations_translations', 'unit_label')) {
                $table->dropColumn('unit_label');
            }
        });
    }
};
