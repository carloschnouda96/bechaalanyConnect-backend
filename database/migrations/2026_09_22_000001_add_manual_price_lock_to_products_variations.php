<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier-sourced variation's `price` was SYNC-OWNED with no way out: every
 * run of SupplierCatalogSync (and every profit-% edit, via
 * Product::recalculateSupplierPrices()) recomputed it from `cost_price x
 * profit%` and overwrote whatever an admin had typed into the CMS, "a short
 * period" (the next hourly sync) after they saved it. CatalogPricingController
 * even refused direct price edits for supplier products for exactly this
 * reason — the fix belongs in the ownership model, not in blocking the edit.
 *
 * This gives `price` a second, higher-priority owner, following the same
 * one-writer-per-column shape as `is_active` (see
 * 2026_09_17_000001_supplier_status_replaces_availability_flags): the new
 * `manual_price` column starts false (sync-owned, unchanged behaviour) and
 * flips true the moment anyone other than the sync engine changes `price`
 * (App\Observers\ProductsVariationObserver). From then on the sync keeps
 * `cost_price`/`external_price`/`supplier_status` current but never writes
 * `price` for that row again — see SupplierCatalogSync's class docblock and
 * Product::recalculateSupplierPrices().
 *
 * `manual_price` is a plain boolean, which round-trips cleanly through the
 * CMS's argument-less type re-assertion (see the migration_type table in
 * 2026_08_10_000001_register_supplier_columns_in_cms.php), so it needs no
 * SchemaManifest entry. It carries no visible checkbox — the lock is set
 * automatically, not toggled — so it is hidden on the index/create/edit forms
 * and shown read-only on the show page, the same shape as `supplier_status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'manual_price')) {
                $table->boolean('manual_price')->default(false)->nullable()->after('price');
            }
        });

        $this->addField('products-variations', [
            'name' => 'manual_price',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Set automatically the moment Price is edited directly. Once on, the supplier sync stops writing Price for this row (Cost price keeps syncing). Read-only here — there is currently no CMS control to switch it back off.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->updateFieldDescription(
            'products-variations',
            'price',
            'Selling price shown to customers. For a supplier-sourced variation this is recomputed from Cost price x the product\'s profit % on every sync — edit it here to take it over yourself; the sync will keep Cost price current but will stop touching Price for this row (see the read-only Manual price field below).'
        );
    }

    public function down(): void
    {
        $this->removeField('products-variations', 'manual_price');
        $this->updateFieldDescription('products-variations', 'price', null);

        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'manual_price')) {
                $table->dropColumn('manual_price');
            }
        });
    }

    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (!in_array($field['name'], array_column($fields, 'name'), true)) {
            $fields[] = $field;
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }

    private function removeField(string $route, string $name): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== $name));
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    /** Updates one field's `description` in place, leaving every other attribute untouched. */
    private function updateFieldDescription(string $route, string $name, ?string $description): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $changed = false;
        foreach ($fields as &$f) {
            if ($f['name'] === $name) {
                $f['description'] = $description;
                $changed = true;
                break;
            }
        }
        unset($f);
        if ($changed) {
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
