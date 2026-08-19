<?php

namespace App\Services\Cms;

/**
 * Declarative record of the schema facts the hellotree CMS cannot preserve.
 *
 * The CMS rebuilds a table from `cms_pages.fields` on every page-schema save. Two
 * of the things it does are impossible to express through that field list:
 *
 *   - the column TYPE is re-asserted from an argument-less Blueprint call
 *     (CmsPagesController.php:382-390), so `decimal` always means `decimal(8,2)`
 *     and there is no way to say `decimal(20,8)`;
 *   - the column is forced NULLable regardless of the `nullable` flag (:388,
 *     `? 'nullable' : 'nullable'` — a vendor typo), so `NOT NULL` never survives.
 *
 * Indexes and foreign keys are NOT rebuilt or dropped by the CMS (editDatabase
 * issues no dropIndex/dropForeign); they only die as collateral when their column
 * is dropped. They are listed here anyway so that `cms:repair-schema --check` can
 * act as a standing regression net after any schema work.
 *
 * This file is the single source of truth: both SchemaGuard and the migrations that
 * introduce these facts read from it, so the two cannot drift apart.
 */
class SchemaManifest
{
    /**
     * @return array<string, array{
     *     columns?: array<string, array{type: string, null?: bool, default?: string|null}>,
     *     indexes?: array<string, array{columns: array<int,string>, unique?: bool}>,
     * }>
     */
    public static function tables(): array
    {
        return [
            'users' => [
                'columns' => [
                    // All three were DOUBLE(8,2) and NULLable with no default. The
                    // cumulative ones especially need headroom beyond the CMS's
                    // implicit decimal(8,2).
                    'credits_balance' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                    'total_purchases' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                    'received_amount' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                ],
            ],

            'credits_transfer' => [
                'columns' => [
                    // Was INT: a $10.50 top-up was stored and credited as $10.
                    'amount' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                    'credits_applied_status' => ['type' => 'tinyint(3) unsigned', 'null' => true],
                ],
            ],

            'product_price_variations' => [
                'columns' => [
                    'price' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                ],
            ],

            'products_variations' => [
                'columns' => [
                    'price' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                    // cost_price is deliberately absent: it stays DOUBLE so sub-cent
                    // Perfect-Panel unit costs are not rounded to zero.
                    // The supplier unit cost of record. `decimal` in cms_pages would
                    // narrow this to (8,2); a Perfect-Panel Default service priced at
                    // rate/1000 is legitimately sub-cent, and rounding it to 0.00
                    // makes computeSellingPrice() publish the product for free.
                    // Registered as `double` in cms_pages to survive the type loop
                    // without that rounding; restored to exact decimal here.
                    'external_price' => ['type' => 'decimal(20,8)', 'null' => true],
                ],
                'indexes' => [
                    'products_variations_external_id_index' => ['columns' => ['external_id']],
                ],
            ],

            'fixed_settings' => [
                'columns' => [
                    // LBP→USD divisors. Registered as `decimal` in cms_pages, which
                    // would narrow them to (8,2) — silently changing the exchange rate
                    // used to price two entire supplier catalogues.
                    'lbp_per_usd' => ['type' => 'decimal(20,4)', 'null' => true],
                    'usharez_lbp_per_usd' => ['type' => 'decimal(20,4)', 'null' => true],
                    'default_profit_percentage' => ['type' => 'decimal(8,2)', 'null' => false, 'default' => '0.00'],
                ],
            ],

            'products' => [
                'columns' => [
                    'profit_percentage' => ['type' => 'decimal(8,2)', 'null' => true],
                    'import_excluded' => ['type' => 'tinyint(1)', 'null' => false, 'default' => '0'],
                ],
                'indexes' => [
                    'products_external_source_id_index' => ['columns' => ['external_source', 'external_id']],
                ],
            ],

            'orders' => [
                'columns' => [
                    'external_response' => ['type' => 'json', 'null' => true],
                    // Money. The CMS can only express `decimal`, which means
                    // decimal(8,2) — a $999,999.99 ceiling. Restored to the real
                    // width here after every page save.
                    'total_price' => ['type' => 'decimal(12,2)', 'null' => false, 'default' => '0.00'],
                    'credits_applied_status' => ['type' => 'tinyint(3) unsigned', 'null' => true],
                ],
                'indexes' => [
                    // The fulfillment re-entry guard. Losing this unique constraint
                    // would allow a retried job to place a duplicate supplier order.
                    'orders_external_order_uuid_unique' => ['columns' => ['external_order_uuid'], 'unique' => true],
                ],
            ],

            'supplier_categories' => [
                'columns' => [
                    'source' => ['type' => 'varchar(191)', 'null' => false, 'default' => 'yassen'],
                    'import_enabled' => ['type' => 'tinyint(1)', 'null' => true, 'default' => '0'],
                ],
                'indexes' => [
                    'supplier_categories_source_external_unique' => [
                        'columns' => ['source', 'external_id'],
                        'unique' => true,
                    ],
                ],
            ],
        ];
    }

    /** Tables this manifest knows about. */
    public static function tableNames(): array
    {
        return array_keys(self::tables());
    }

    /** @return array<string, mixed>|null */
    public static function for(string $table): ?array
    {
        return self::tables()[$table] ?? null;
    }
}
