<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns and lookup table that link the local catalog to the
 * Yassen-Card supplier API.
 *
 *  - products.external_source / external_id      → which supplier + remote id
 *  - products.profit_percentage                  → per-product markup (null = use the global default)
 *  - products_variations.external_price          → last seen supplier cost (drives change detection)
 *  - products_variations.external_type/_qty_values → supplier product_type + allowed amounts/range
 *  - orders.external_*                            → fulfillment tracking (Yassen order id, uuid, status, payload)
 *  - supplier_categories                          → the admin's per-category import allow-list + tree mapping
 *
 * Selling price = cost_price * (1 + profit%). The supplier cost is stored in the
 * existing products_variations.cost_price column so the CMS profit dashboard keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'external_source')) {
                $table->string('external_source')->nullable()->after('product_type_id');
            }
            if (!Schema::hasColumn('products', 'external_id')) {
                $table->string('external_id')->nullable()->after('external_source');
            }
            if (!Schema::hasColumn('products', 'profit_percentage')) {
                $table->decimal('profit_percentage', 8, 2)->nullable()->after('external_id');
            }
        });
        $this->addIndex('products', 'products_external_source_id_index', ['external_source', 'external_id']);

        Schema::table('products_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('products_variations', 'external_id')) {
                $table->string('external_id')->nullable()->after('cost_price');
            }
            if (!Schema::hasColumn('products_variations', 'external_price')) {
                $table->decimal('external_price', 20, 8)->nullable()->after('external_id');
            }
            if (!Schema::hasColumn('products_variations', 'external_type')) {
                $table->string('external_type')->nullable()->after('external_price');
            }
            if (!Schema::hasColumn('products_variations', 'external_qty_values')) {
                $table->json('external_qty_values')->nullable()->after('external_type');
            }
        });
        $this->addIndex('products_variations', 'products_variations_external_id_index', ['external_id']);

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'external_source')) {
                $table->string('external_source')->nullable()->after('code');
            }
            if (!Schema::hasColumn('orders', 'external_order_id')) {
                $table->string('external_order_id')->nullable()->after('external_source');
            }
            if (!Schema::hasColumn('orders', 'external_order_uuid')) {
                $table->string('external_order_uuid')->nullable()->after('external_order_id');
            }
            if (!Schema::hasColumn('orders', 'external_status')) {
                $table->string('external_status')->nullable()->after('external_order_uuid');
            }
            if (!Schema::hasColumn('orders', 'external_response')) {
                $table->json('external_response')->nullable()->after('external_status');
            }
        });
        $this->addUniqueIndex('orders', 'orders_external_order_uuid_unique', ['external_order_uuid']);

        Schema::table('fixed_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('fixed_settings', 'default_profit_percentage')) {
                $table->decimal('default_profit_percentage', 8, 2)->default(0)->after('admin_email');
            }
        });

        if (!Schema::hasTable('supplier_categories')) {
            Schema::create('supplier_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('cms_draft_flag')->default(0);
                $table->string('source')->default('yassen');
                $table->string('external_id');
                $table->string('parent_external_id')->nullable();
                $table->string('name')->nullable();
                $table->string('image')->nullable();
                $table->boolean('import_enabled')->default(false);
                $table->unsignedBigInteger('category_id')->nullable();
                $table->unsignedBigInteger('subcategory_id')->nullable();
                $table->timestamps();

                $table->unique(['source', 'external_id'], 'supplier_categories_source_external_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $this->dropIndexIfExists('products', 'products_external_source_id_index');
            foreach (['external_source', 'external_id', 'profit_percentage'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('products_variations', function (Blueprint $table) {
            $this->dropIndexIfExists('products_variations', 'products_variations_external_id_index');
            foreach (['external_id', 'external_price', 'external_type', 'external_qty_values'] as $col) {
                if (Schema::hasColumn('products_variations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $this->dropIndexIfExists('orders', 'orders_external_order_uuid_unique');
            foreach (['external_source', 'external_order_id', 'external_order_uuid', 'external_status', 'external_response'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('fixed_settings', function (Blueprint $table) {
            if (Schema::hasColumn('fixed_settings', 'default_profit_percentage')) {
                $table->dropColumn('default_profit_percentage');
            }
        });

        Schema::dropIfExists('supplier_categories');
    }

    private function addIndex(string $table, string $name, array $columns): void
    {
        if (!$this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    private function addUniqueIndex(string $table, string $name, array $columns): void
    {
        if (!$this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return count(
            \Illuminate\Support\Facades\DB::select(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
                [$name]
            )
        ) > 0;
    }
};
