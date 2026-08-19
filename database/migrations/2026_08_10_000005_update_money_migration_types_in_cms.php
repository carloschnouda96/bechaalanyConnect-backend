<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paired with 2026_08_10_000004: teaches the CMS what the money columns now are.
 *
 * The DDL migration is only half the job. CmsPagesController::editDatabase() re-asserts
 * every registered column's type from `cms_pages.fields[].migration_type` on every page
 * save, so leaving these entries as they were would silently undo the conversion the
 * first time an admin opened one of these pages and pressed Save:
 *
 *   orders.total_price               longText → LONGTEXT   (money back to free text)
 *   users.credits_balance            float    → DOUBLE
 *   users.total_purchases            float    → DOUBLE
 *   users.received_amount            float    → DOUBLE
 *   credits_transfer.amount          integer  → INT        (cents truncated again)
 *   products_variations.price        float    → DOUBLE
 *   product_price_variations.price   float    → DOUBLE
 *
 * `decimal` is the closest the CMS can express; it yields decimal(8,2). SchemaGuard
 * then restores the real decimal(12,2) immediately after the save, so the window is a
 * single request and no value is truncated (both are 2dp).
 *
 * form_field is moved to `number` where it was `text`, so the CMS renders a numeric
 * input and its auto-derived validation is numeric too.
 */
return new class extends Migration
{
    /** route => [column => [migration_type, form_field]] */
    private const CHANGES = [
        'orders' => [
            'total_price' => ['decimal', 'number'],
        ],
        'users' => [
            'credits_balance' => ['decimal', 'number'],
            'total_purchases' => ['decimal', 'number'],
            'received_amount' => ['decimal', 'number'],
        ],
        'credits-transfer' => [
            'amount' => ['decimal', 'number'],
        ],
        'products-variations' => [
            'price' => ['decimal', 'number'],
        ],
        'product-price-variations' => [
            'price' => ['decimal', 'number'],
        ],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $route => $columns) {
            foreach ($columns as $column => [$migrationType, $formField]) {
                $this->setFieldType($route, $column, $migrationType, $formField);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op. Restoring `longText` / `float` / `integer` here would
        // re-arm the exact data corruption the forward migration removed: the next CMS
        // page save would turn money back into text and truncate cents.
    }

    private function setFieldType(string $route, string $column, string $migrationType, string $formField): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        $changed = false;

        foreach ($fields as &$field) {
            if (($field['name'] ?? null) !== $column) {
                continue;
            }

            $field['migration_type'] = $migrationType;
            $field['form_field'] = $formField;
            $changed = true;
        }
        unset($field);

        if ($changed) {
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
