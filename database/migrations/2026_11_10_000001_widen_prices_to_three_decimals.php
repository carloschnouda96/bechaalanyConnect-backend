<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prices, markup percentages and the balances they are charged against carry 3
 * decimals (28.205 %, $1.953). Widening DECIMAL scale is lossless.
 *
 * credits_transfer.amount stays (12,2): a top-up is a payment in cents.
 * App\Services\Cms\SchemaManifest is updated to match, or the next CMS page save
 * would narrow these back to 2dp.
 */
return new class extends Migration
{
    private const COLUMNS = [
        ['products_variations', 'price', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
        ['product_price_variations', 'price', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
        ['products_variations', 'profit_percentage', 'DECIMAL(8,3) NULL'],
        ['product_price_variations', 'profit_percentage', 'DECIMAL(8,3) NULL'],
        ['fixed_settings', 'default_profit_percentage', 'DECIMAL(8,3) NOT NULL DEFAULT 0.000'],
        ['orders', 'total_price', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
        ['users', 'credits_balance', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
        ['users', 'total_purchases', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
        ['users', 'received_amount', 'DECIMAL(12,3) NOT NULL DEFAULT 0.000'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $type]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$type}");
        }
    }

    public function down(): void
    {
        // Narrowing would round real 3dp values; restore a pre-migration dump instead.
    }
};
