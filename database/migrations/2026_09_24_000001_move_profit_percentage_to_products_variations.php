<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the markup from the product to the variation, so each variation of a
 * product can carry its own profit % (a cheap bundle at 20%, the large one at 8%).
 *
 * The fallback chain loses its middle tier: an empty variation profit % now falls
 * straight back to `fixed_settings.default_profit_percentage`. Every existing
 * product-level value is copied down to that product's variations first, with raw
 * SQL so no model event fires — prices already equal cost x that %, so nothing is
 * repriced and no `manual_price` lock is set or cleared.
 *
 * `products.profit_percentage` is dropped explicitly rather than just unregistered:
 * the next CMS save of the Products page would drop an unregistered column anyway
 * (see CLAUDE.md, "The CMS rewrites your schema"), so leaving it would only mean
 * a stale column the code no longer reads.
 *
 * The variation column is registered as bare `decimal`, which the CMS re-asserts
 * as decimal(8,2) — the column's real type — so it needs no SchemaManifest entry.
 * Editing it is handled by App\Observers\ProductsVariationObserver, which
 * recomputes Price from Cost and clears `manual_price`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'profit_percentage')) {
                $table->decimal('profit_percentage', 8, 2)->nullable()->after('cost_price');
            }
        });

        if (Schema::hasColumn('products', 'profit_percentage')) {
            DB::statement(
                'UPDATE products_variations v
                 JOIN products p ON p.id = v.product_id
                 SET v.profit_percentage = p.profit_percentage
                 WHERE p.profit_percentage IS NOT NULL AND v.profit_percentage IS NULL'
            );
        }

        $this->addField('products-variations', [
            'name' => 'profit_percentage',
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Markup % applied to Cost price for this variation (e.g. 50 sells a $1 item for $1.50). Leave empty to use the default in Fixed Settings. Changing it recomputes Price from Cost price and clears Manual price.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ], 'cost_price');

        $this->updateFieldDescription(
            'products-variations',
            'price',
            'Selling price shown to customers. For a supplier-sourced variation this is recomputed from Cost price x this variation\'s profit % on every sync — edit it here to take it over yourself; the sync will keep Cost price current but will stop touching Price for this row (see the read-only Manual price field below). Changing Profit % hands Price back to the sync.'
        );

        $this->removeField('products', 'profit_percentage');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'profit_percentage')) {
                $table->dropColumn('profit_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'profit_percentage')) {
                $table->decimal('profit_percentage', 8, 2)->nullable()->after('external_id');
            }
        });

        if (Schema::hasColumn('products_variations', 'profit_percentage')) {
            DB::statement(
                'UPDATE products p
                 JOIN (SELECT product_id, MAX(profit_percentage) AS pct
                       FROM products_variations
                       WHERE profit_percentage IS NOT NULL
                       GROUP BY product_id) v ON v.product_id = p.id
                 SET p.profit_percentage = v.pct'
            );
        }

        $this->addField('products', [
            'name' => 'profit_percentage',
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Markup % applied to the supplier cost for this product (e.g. 50 sells a $1 item for $1.50). Leave empty to use the global default in Fixed Settings.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->removeField('products-variations', 'profit_percentage');
        $this->updateFieldDescription(
            'products-variations',
            'price',
            'Selling price shown to customers. For a supplier-sourced variation this is recomputed from Cost price x the product\'s profit % on every sync — edit it here to take it over yourself; the sync will keep Cost price current but will stop touching Price for this row (see the read-only Manual price field below).'
        );

        Schema::table('products_variations', function (Blueprint $table) {
            if (Schema::hasColumn('products_variations', 'profit_percentage')) {
                $table->dropColumn('profit_percentage');
            }
        });
    }

    /** Adds a field, placed right after `$after` when that field exists, else appended. */
    private function addField(string $route, array $field, ?string $after = null): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (in_array($field['name'], array_column($fields, 'name'), true)) {
            return;
        }
        $position = $after === null ? false : array_search($after, array_column($fields, 'name'), true);
        if ($position === false) {
            $fields[] = $field;
        } else {
            array_splice($fields, $position + 1, 0, [$field]);
        }
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
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
