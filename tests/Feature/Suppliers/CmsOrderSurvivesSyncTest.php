<?php

namespace Tests\Feature\Suppliers;

use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierOrderResult;
use App\Services\Suppliers\SupplierProduct;
use App\SupplierCategory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin's CMS drag-and-drop order (`ht_pos`) must survive a supplier import.
 *
 * The CMS Order page and list sort products_variations / products by `ht_pos`
 * alone, table-wide. A sync used to leave new rows at NULL (sorted first) or, for
 * grouped categories, give them a per-product MAX + 1 that collided with other
 * products' positions — and MySQL leaves ties in no defined order, so the admin's
 * order shuffled after every import. Now a new row is appended after the global
 * max and an existing position is never rewritten.
 */
class CmsOrderSurvivesSyncTest extends TestCase
{
    private const SOURCE = 'test-cms-order';
    private const GROUPED = 'order-grouped';
    private const UNGROUPED = 'order-ungrouped';

    public function test_admin_order_survives_an_import_and_new_rows_are_appended(): void
    {
        $connector = new FakeOrderConnector(self::SOURCE, [
            $this->dto(self::GROUPED, 'g:1', 'Bundle 1'),
            $this->dto(self::GROUPED, 'g:2', 'Bundle 2'),
            $this->dto(self::UNGROUPED, 'u:1', 'Card 1'),
            $this->dto(self::UNGROUPED, 'u:2', 'Card 2'),
        ]);
        $this->enableCategories($connector);
        (new SupplierCatalogSync())->sync($connector);

        // The admin re-drags everything into a reversed, table-wide order.
        $mine = $this->variations()->reverse()->values();
        foreach ($mine as $i => $variation) {
            DB::table('products_variations')->where('id', $variation->id)->update(['ht_pos' => 1000 + $i]);
        }
        $adminOrder = DB::table('products_variations')->whereIn('id', $mine->pluck('id'))->pluck('ht_pos', 'id')->all();
        $globalMax = (int) DB::table('products_variations')->max('ht_pos');

        $connector->catalog[] = $this->dto(self::GROUPED, 'g:3', 'Bundle 3');
        $connector->catalog[] = $this->dto(self::UNGROUPED, 'u:3', 'Card 3');
        $connector->catalog[0] = $this->dto(self::GROUPED, 'g:1', 'Bundle 1', 99.0); // price change
        (new SupplierCatalogSync())->sync($connector);

        $after = DB::table('products_variations')->whereIn('id', array_keys($adminOrder))->pluck('ht_pos', 'id')->all();
        $this->assertEquals($adminOrder, $after, 'no admin-set position may move');

        $new = $this->variations()->whereIn('external_id', ['g:3', 'u:3'])->sortBy('ht_pos')->values();
        $this->assertCount(2, $new);
        foreach ($new as $variation) {
            $this->assertGreaterThan($globalMax, (int) $variation->ht_pos);
        }
        $this->assertSame(['g:3', 'u:3'], $new->pluck('external_id')->all(), 'appended in feed order');
        $this->assertNoDuplicatePositions('products_variations');
    }

    public function test_grouped_variations_never_collide_with_another_products_positions(): void
    {
        // Another supplier's product already holds positions 1..3, as the admin's
        // global 1..N does. The old per-product MAX + 1 gave the grouped rows 1..3 too.
        $other = new FakeOrderConnector(self::SOURCE . '-other', [
            $this->dto(self::UNGROUPED, 'o:1', 'Other 1'),
            $this->dto(self::UNGROUPED, 'o:2', 'Other 2'),
            $this->dto(self::UNGROUPED, 'o:3', 'Other 3'),
        ]);
        $this->enableCategories($other);
        (new SupplierCatalogSync())->sync($other);
        DB::table('products_variations')->update(['ht_pos' => null]);
        $position = 0;
        foreach (DB::table('products_variations')->orderBy('id')->pluck('id') as $id) {
            DB::table('products_variations')->where('id', $id)->update(['ht_pos' => ++$position]);
        }
        $globalMax = (int) DB::table('products_variations')->max('ht_pos');
        $this->assertGreaterThanOrEqual(3, $globalMax);

        $connector = new FakeOrderConnector(self::SOURCE, [
            $this->dto(self::GROUPED, 'g:1', 'Bundle 1'),
            $this->dto(self::GROUPED, 'g:2', 'Bundle 2'),
            $this->dto(self::GROUPED, 'g:3', 'Bundle 3'),
        ]);
        $this->enableCategories($connector);
        (new SupplierCatalogSync())->sync($connector);

        $variations = $this->variations()->sortBy('ht_pos')->values();
        $this->assertSame(['g:1', 'g:2', 'g:3'], $variations->pluck('external_id')->all());
        foreach ($variations as $variation) {
            $this->assertGreaterThan($globalMax, (int) $variation->ht_pos);
        }
        $this->assertNoDuplicatePositions('products_variations');
    }

    public function test_ungrouped_products_and_variations_get_a_unique_position(): void
    {
        $connector = new FakeOrderConnector(self::SOURCE, [
            $this->dto(self::UNGROUPED, 'u:1', 'Card 1'),
            $this->dto(self::UNGROUPED, 'u:2', 'Card 2'),
        ]);
        $this->enableCategories($connector);
        (new SupplierCatalogSync())->sync($connector);

        $products = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)->orderBy('ht_pos')->get();
        $this->assertCount(2, $products);
        $this->assertSame(['u:1', 'u:2'], $products->pluck('external_id')->all());
        $this->assertNotContains(null, $products->pluck('ht_pos')->all());
        $this->assertNotContains(null, $this->variations()->pluck('ht_pos')->all());

        // A re-sync never renumbers them.
        $before = $products->pluck('ht_pos', 'id')->all();
        (new SupplierCatalogSync())->sync($connector);
        $this->assertEquals($before, DB::table('products')->whereIn('id', array_keys($before))->pluck('ht_pos', 'id')->all());
    }

    public function test_migration_renumbers_ties_and_nulls_keeping_the_visible_order(): void
    {
        $connector = new FakeOrderConnector(self::SOURCE, [
            $this->dto(self::UNGROUPED, 'u:1', 'Card 1'),
            $this->dto(self::UNGROUPED, 'u:2', 'Card 2'),
            $this->dto(self::UNGROUPED, 'u:3', 'Card 3'),
            $this->dto(self::UNGROUPED, 'u:4', 'Card 4'),
        ]);
        $this->enableCategories($connector);
        (new SupplierCatalogSync())->sync($connector);

        // The broken state: a tie, a negative CMS-created position, and NULLs.
        $ids = $this->variations()->pluck('id')->all();
        DB::table('products_variations')->where('id', $ids[0])->update(['ht_pos' => 5]);
        DB::table('products_variations')->where('id', $ids[1])->update(['ht_pos' => 5]);
        DB::table('products_variations')->where('id', $ids[2])->update(['ht_pos' => -3]);
        DB::table('products_variations')->where('id', $ids[3])->update(['ht_pos' => null]);

        foreach (['products_variations', 'products'] as $table) {
            $expected[$table] = DB::table($table)->orderByRaw('ht_pos IS NULL, ht_pos, id')->pluck('id')->all();
        }

        $migration = require base_path('database/migrations/2026_09_28_000001_normalize_ht_pos_for_products_and_variations.php');
        $migration->up();

        foreach (['products_variations', 'products'] as $table) {
            $positions = DB::table($table)->orderBy('ht_pos')->pluck('ht_pos', 'id');
            $this->assertSame($expected[$table], $positions->keys()->all(), "$table keeps its visible order");
            $this->assertSame(range(1, count($expected[$table])), $positions->map(fn ($p) => (int) $p)->values()->all());
        }

        // Idempotent.
        $snapshot = DB::table('products_variations')->pluck('ht_pos', 'id')->all();
        $migration->up();
        $this->assertEquals($snapshot, DB::table('products_variations')->pluck('ht_pos', 'id')->all());
    }

    // ---------------------------------------------------------------- helpers

    private function dto(string $category, string $externalId, string $name, float $cost = 10.0): SupplierProduct
    {
        return new SupplierProduct(
            externalId: $externalId,
            name: $name,
            categoryExternalId: $category,
            categoryName: $category === self::GROUPED ? 'Grouped Bundles' : 'Gift Cards',
            categoryImage: null,
            unitCost: $cost,
            available: true,
            productTypeId: 3,
            qtyValues: ['min' => 1, 'max' => 1],
            externalType: 'test',
        );
    }

    /** Discovery pass, then the admin's CMS toggles — grouped for one category only. */
    private function enableCategories(SupplierConnector $connector): void
    {
        (new SupplierCatalogSync())->sync($connector, true);

        foreach (SupplierCategory::withoutGlobalScope('cms_draft_flag')->where('source', $connector->key())->get() as $category) {
            $category->import_enabled = true;
            $category->group_as_single_product = $category->external_id === self::GROUPED;
            $category->save();
        }
    }

    private function variations()
    {
        return ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->whereHas('product', fn ($q) => $q->withoutGlobalScope('cms_draft_flag')->where('external_source', self::SOURCE))
            ->orderBy('id')
            ->get();
    }

    private function assertNoDuplicatePositions(string $table): void
    {
        $duplicates = DB::table($table)->whereNotNull('ht_pos')
            ->select('ht_pos')->groupBy('ht_pos')->havingRaw('COUNT(*) > 1')->pluck('ht_pos')->all();
        $this->assertSame([], $duplicates, "$table has tied positions");
    }
}

class FakeOrderConnector implements SupplierConnector
{
    public function __construct(private string $key, public array $catalog = [])
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetchCatalog(): array
    {
        return $this->catalog;
    }

    public function placeOrder(Order $order, ProductsVariation $variation): SupplierOrderResult
    {
        return new SupplierOrderResult(externalOrderId: 'fake', status: SupplierOrderResult::COMPLETED, raw: []);
    }

    public function checkOrder(Order $order): SupplierOrderResult
    {
        return new SupplierOrderResult(externalOrderId: 'fake', status: SupplierOrderResult::COMPLETED, raw: []);
    }

    public function balance(): ?float
    {
        return null;
    }
}
