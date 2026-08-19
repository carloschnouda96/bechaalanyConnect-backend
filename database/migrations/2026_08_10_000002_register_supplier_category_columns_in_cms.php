<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-arms the fix that 2026_06_18_000004_repair_supplier_categories_columns.php
 * only half-completed.
 *
 * That migration restored the five columns a CMS page save had dropped from
 * `supplier_categories`, but it never registered four of them as CMS fields — so
 * the identical incident was still one "Save" away from happening again:
 *
 *   parent_external_id  → supplier category tree flattens; child categories
 *                         detach from their parent
 *   image               → the category artwork the sync imports
 *   category_id         ┐ the admin's mapping from a supplier category to a site
 *   subcategory_id      ┘ category/subcategory
 *
 * (`source`, `external_id`, `name` and `import_enabled` were already registered.)
 *
 * WHY category_id / subcategory_id ARE `select`, NOT PLAIN NUMBERS
 * ---------------------------------------------------------------
 * Two reasons.
 *
 * 1. Type safety. Both are `bigint unsigned`, and editDatabase's type-reversion
 *    loop (CmsPagesController.php:382-390) re-asserts a registered column's type
 *    on every save from an argument-less Blueprint call. `bigInteger` alone emits
 *    a SIGNED bigint. Fields whose form_field is `select` are explicitly skipped
 *    by that loop (:385), so the unsigned type is preserved exactly. On a fresh
 *    create the `select` branch adds ->unsigned() (:368), so the declared
 *    `bigInteger` is still correct.
 *
 * 2. It exposes a capability the admin already has but cannot reach.
 *    SupplierCatalogSync::resolveCategory/resolveSubcategory (:234-235, :251-252)
 *    treat a non-null category_id/subcategory_id as an admin override — "put this
 *    supplier category under this site category" — and only auto-create when it is
 *    null, writing the resolution back (:269-271). Because the columns were never
 *    registered, that override has been invisible and unusable in the CMS. Making
 *    them editable selects turns it on with no code change.
 *
 * `image` and `parent_external_id` stay sync-owned: visible on the show page for
 * troubleshooting, hidden from the create/edit forms. `image` is deliberately a
 * plain `text` field rather than form_field `image` — it holds a supplier-hosted
 * URL, and the CMS image widget would run it through Storage::url() and render a
 * broken thumbnail.
 */
return new class extends Migration
{
    private const ROUTE = 'supplier-categories';

    public function up(): void
    {
        $this->addField(self::ROUTE, [
            'name' => 'parent_external_id',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Supplier-side id of this category\'s parent, for suppliers that expose a category tree. Set by the catalog sync — do not edit.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->addField(self::ROUTE, [
            'name' => 'image',
            'migration_type' => 'string',
            'form_field' => 'text',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Category artwork URL as provided by the supplier. Set by the catalog sync — do not edit.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->addField(self::ROUTE, [
            'name' => 'category_id',
            'migration_type' => 'bigInteger',
            'form_field' => 'select',
            'form_field_additionals_1' => 'categories',
            'form_field_additionals_2' => 'title',
            'description' => 'Optional: force imported products from this supplier category into a specific site category. Leave empty to let the sync create/match one automatically.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->addField(self::ROUTE, [
            'name' => 'subcategory_id',
            'migration_type' => 'bigInteger',
            'form_field' => 'select',
            'form_field_additionals_1' => 'subcategories',
            'form_field_additionals_2' => 'title',
            'description' => 'Optional: force imported products from this supplier category into a specific site subcategory. Leave empty to let the sync create/match one automatically.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);
    }

    public function down(): void
    {
        // No-op on purpose — see 2026_08_10_000001. Un-registering would re-arm
        // the data-loss bug rather than reverse it.
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
