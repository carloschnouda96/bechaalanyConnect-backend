<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Protects the nine supplier-integration columns that exist in the database but
 * were never registered as CMS fields.
 *
 * WHY THIS IS URGENT
 * ------------------
 * Hellotreedigital\Cms\Controllers\CmsPagesController::editDatabase() rebuilds a
 * table from `cms_pages.fields` on every page-schema save. Its delete loop
 * (CmsPagesController.php:454-472) walks Schema::getColumnListing() and drops any
 * column that is not in the submitted field list. Only `id`, `cms_draft_flag`,
 * `ht_pos`, `created_at` and `updated_at` are exempt.
 *
 * These columns are currently unprotected:
 *
 *   orders.external_source          → SupplierRegistry can no longer resolve the connector
 *   orders.external_order_uuid      → SupplierOrderFulfillment's ONLY re-entry guard.
 *                                     Losing it lets a retried job re-place an order,
 *                                     i.e. DOUBLE-CHARGING the supplier.
 *   orders.external_response        → the delivered card codes / raw supplier payload
 *   products.external_source        ┐ the composite identity the catalog sync matches on.
 *   products.external_id            ┘ Losing them also destroys products_external_source_id_index
 *                                     and makes the next sync re-import every supplier
 *                                     product as a duplicate.
 *   products_variations.external_id          ┐
 *   products_variations.external_price       │ price-change detection, qty presets, and the
 *   products_variations.external_type        │ per-unit cost of record. The next sync dies
 *   products_variations.external_qty_values  ┘ with "Unknown column".
 *
 * This already happened once in production to `supplier_categories` — see
 * 2026_06_18_000004_repair_supplier_categories_columns.php.
 *
 * CHOICE OF migration_type
 * ------------------------
 * editDatabase also re-asserts every registered column's type on every save via
 *   $table->{migration_type}($name)->nullable()->change()      (CmsPagesController.php:382-390)
 * with NO arguments, so `migration_type` must be the Blueprint method whose bare
 * form reproduces the live type. Verified empirically against this database:
 *
 *   string   →  varchar(191)     round-trips
 *   json     →  json             round-trips
 *   double   →  double           (does not round-trip decimal(20,8), but see below)
 *   decimal  →  decimal(8,2)     NARROWS
 *
 * `products_variations.external_price` is decimal(20,8). There is no argument-less
 * Blueprint call that reproduces that, so it cannot round-trip here. It is registered
 * as `double` rather than `decimal` deliberately: a save would turn a real sub-cent
 * Perfect-Panel unit cost (e.g. 0.00090000, from `rate / 1000` on a Default service)
 * into 0.00 under `decimal`, and ProductsVariation::computeSellingPrice() would then
 * publish that product for free. Under `double` the value survives as 0.0009.
 * The exact decimal(20,8) type is restored by the CMS schema guard (P3); the following
 * catalog sync then rewrites the values at full precision either way.
 *
 * All nine are hidden from the index and from the create/edit forms — they are
 * machine-owned. They stay visible on the show page so an admin can inspect a
 * failed fulfillment. This mirrors
 * 2026_06_18_000005_register_order_external_fields_in_cms.php.
 */
return new class extends Migration
{
    /**
     * route => list of fields to register.
     */
    private function fields(): array
    {
        return [
            'orders' => [
                $this->field(
                    'external_source',
                    'string',
                    'text',
                    'Supplier key that fulfills this order (yassen / swift / 1xpanel / usharez / umanage). Set by the catalog sync — do not edit.'
                ),
                $this->field(
                    'external_order_uuid',
                    'string',
                    'text',
                    'Idempotency key for supplier fulfillment. Machine-owned — editing or clearing it can cause the order to be placed at the supplier twice.'
                ),
                $this->field(
                    'external_response',
                    'json',
                    'textarea',
                    'Raw supplier payload for the last placement/poll (includes delivered codes where the supplier returns them). Read-only.'
                ),
            ],
            'products' => [
                $this->field(
                    'external_source',
                    'string',
                    'text',
                    'Supplier this product was imported from. Half of the identity the catalog sync matches on — do not edit.'
                ),
                $this->field(
                    'external_id',
                    'string',
                    'text',
                    'Supplier-side product id. Half of the identity the catalog sync matches on — do not edit.'
                ),
            ],
            'products-variations' => [
                $this->field(
                    'external_id',
                    'string',
                    'text',
                    'Supplier-side product/service id for this variation. Set by the catalog sync — do not edit.'
                ),
                $this->field(
                    'external_price',
                    'double',
                    'number',
                    'Supplier unit cost of record, at full precision. Set by the catalog sync — do not edit.'
                ),
                $this->field(
                    'external_type',
                    'string',
                    'text',
                    'Supplier-side service type (e.g. Perfect Panel Package / Default), which decides how unit cost is derived.'
                ),
                $this->field(
                    'external_qty_values',
                    'json',
                    'textarea',
                    'Preset quantities the supplier accepts, rendered as amount options on the storefront. Read-only.'
                ),
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->fields() as $route => $fields) {
            foreach ($fields as $field) {
                $this->addField($route, $field);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op for the field list.
        //
        // Removing these registrations would re-arm the exact data-loss bug this
        // migration exists to prevent: the columns would stay in the database but
        // become droppable again by the next CMS page save. Rolling back must not
        // make the schema less safe than rolling forward.
    }

    /**
     * Build a field row in the shape hellotree stores in cms_pages.fields.
     * Machine-owned: hidden from the index and from both forms, visible on show.
     */
    private function field(string $name, string $migrationType, string $formField, string $description): array
    {
        return [
            'name' => $name,
            'migration_type' => $migrationType,
            'form_field' => $formField,
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => $description,
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ];
    }

    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        if (in_array($field['name'], array_column($fields, 'name'), true)) {
            return;
        }

        $fields[] = $field;
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
