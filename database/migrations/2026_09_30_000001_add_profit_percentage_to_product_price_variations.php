<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a user-type price be a profit % on cost, not only a fixed price.
 *
 * `profit_percentage` NULL keeps the row in fixed mode (the admin owns `price`). When it
 * is set, App\Observers\ProductPriceVariationObserver derives `price` from the
 * variation's cost with ProductsVariation::computeSellingPrice(), and
 * ProductsVariationObserver re-derives it whenever that cost moves. `price` therefore
 * always holds the resolved figure, so OrderController::saveOrder and the storefront
 * keep reading it unchanged.
 *
 * Registered as bare `decimal`, which the CMS re-asserts as decimal(8,2) — the
 * column's real type — so it needs no SchemaManifest column entry.
 *
 * The UNIQUE (products_variations_id, user_types_id) lets the Price matrix upsert one
 * row per cell. Duplicates are removed first keeping the LOWEST id per pair: that is
 * the row `priceVariations->firstWhere()` resolves today, so no customer's price moves.
 */
return new class extends Migration
{
    private const ROUTE = 'product-price-variations';
    private const UNIQUE = 'product_price_variations_variation_user_type_unique';

    public function up(): void
    {
        Schema::table('product_price_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('product_price_variations', 'profit_percentage')) {
                $table->decimal('profit_percentage', 8, 2)->nullable()->after('price');
            }
        });

        DB::statement(
            'DELETE p FROM product_price_variations p
             JOIN product_price_variations keep
               ON keep.products_variations_id = p.products_variations_id
              AND keep.user_types_id = p.user_types_id
              AND keep.id < p.id'
        );

        if (!$this->hasIndex(self::UNIQUE)) {
            Schema::table('product_price_variations', function (Blueprint $table) {
                $table->unique(['products_variations_id', 'user_types_id'], self::UNIQUE);
            });
        }

        $this->addField([
            'name' => 'profit_percentage',
            'migration_type' => 'decimal',
            'form_field' => 'number',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Markup % on the variation\'s Cost price for this user type (e.g. 10 sells a $1 cost for $1.10). Leave empty to use Price as a fixed price. When set, Price is recomputed from cost automatically — including whenever the supplier cost changes.',
            'hide_index' => 0,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ], 'price');
    }

    public function down(): void
    {
        $this->removeField('profit_percentage');

        if ($this->hasIndex(self::UNIQUE)) {
            // MySQL dropped the FK's own index once the unique one covered it, and
            // refuses to drop the last index a foreign key can use.
            Schema::table('product_price_variations', function (Blueprint $table) {
                $table->index('products_variations_id', 'product_price_variations_products_variations_id_foreign');
            });
            Schema::table('product_price_variations', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }

        Schema::table('product_price_variations', function (Blueprint $table) {
            if (Schema::hasColumn('product_price_variations', 'profit_percentage')) {
                $table->dropColumn('profit_percentage');
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'product_price_variations')
            ->where('index_name', $name)
            ->exists();
    }

    /** Adds a field, placed right after `$after` when that field exists, else appended. */
    private function addField(array $field, ?string $after = null): void
    {
        $page = DB::table('cms_pages')->where('route', self::ROUTE)->first();
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
        DB::table('cms_pages')->where('route', self::ROUTE)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    private function removeField(string $name): void
    {
        $page = DB::table('cms_pages')->where('route', self::ROUTE)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== $name));
        DB::table('cms_pages')->where('route', self::ROUTE)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }
};
