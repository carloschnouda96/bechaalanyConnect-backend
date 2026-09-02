<?php

namespace Tests\Feature\Cms;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use App\Services\Cms\CatalogCsv;
use App\Services\Cms\CatalogImporter;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Bulk catalog editing has to be boring and predictable, because the blast radius is the
 * whole storefront. The two properties that matter:
 *
 *  1. A dry run writes nothing.
 *  2. Supplier-owned products are never touched — their prices are derived from cost ×
 *     profit % by the sync, so an edit here would either be reverted at the next sync or
 *     fight the markup engine until it was.
 */
class CatalogImportTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Import Admin';
        $admin->email = 'import_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function subcategory(): Subcategory
    {
        $suffix = uniqid();

        $category = Category::create(['slug' => 'cat-' . $suffix, 'is_active' => 1]);

        return Subcategory::create([
            'slug' => 'sub-' . $suffix,
            'category_id' => $category->id,
            'is_active' => 1,
        ]);
    }

    private function manualProduct(Subcategory $subcategory, float $price = 10.00): array
    {
        $suffix = uniqid();

        $product = Product::create([
            'slug' => 'prod-' . $suffix,
            'subcategory_id' => $subcategory->id,
            'product_type_id' => 2,
            'is_active' => 1,
        ]);
        $product->translateOrNew('en')->name = 'Original EN';
        $product->translateOrNew('ar')->name = 'Original AR';
        $product->save();

        $variation = ProductsVariation::create([
            'slug' => 'var-' . $suffix,
            'product_id' => $product->id,
            'price' => $price,
            'cost_price' => 5.00,
            'is_active' => 1,
        ]);

        return [$product, $variation];
    }

    /** Builds a CSV whose header is the real one, so the test cannot drift from the app. */
    private function csv(array $rows): string
    {
        $header = CatalogCsv::header();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $header), ',', '"', '');
        }

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return $content;
    }

    private function rowFor(Product $product, ProductsVariation $variation, array $overrides = []): array
    {
        return array_merge([
            'product_slug' => $product->slug,
            'category_slug' => $product->subcategory->category->slug,
            'subcategory_slug' => $product->subcategory->slug,
            'product_type_id' => $product->product_type_id,
            'product_is_active' => 1,
            'variation_slug' => $variation->slug,
            'cost_price' => $variation->cost_price,
            'price' => $variation->price,
            'variation_is_active' => 1,
        ], $overrides);
    }

    private function parse(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog') . '.csv';
        file_put_contents($path, $csv);
        $parsed = CatalogCsv::parse($path);
        unlink($path);

        return $parsed['rows'];
    }

    public function test_export_round_trips_into_an_unchanged_plan(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $rows = $this->parse($this->csv([$this->rowFor($product, $variation)]));
        $plan = (new CatalogImporter())->plan($rows);

        $this->assertSame(CatalogImporter::UNCHANGED, $plan[0]['action'],
            're-importing the current values must be a no-op');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $rows = $this->parse($this->csv([
            $this->rowFor($product, $variation, ['price' => '99.00']),
        ]));

        $plan = (new CatalogImporter())->plan($rows);

        $this->assertSame(CatalogImporter::UPDATE, $plan[0]['action']);
        $this->assertEquals(10.00, (float) $variation->fresh()->price, 'plan() must not write');
    }

    public function test_committing_updates_price_and_both_translations(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $rows = $this->parse($this->csv([
            $this->rowFor($product, $variation, [
                'price' => '12.50',
                'product_name_en' => 'Renamed EN',
                'product_name_ar' => 'Renamed AR',
            ]),
        ]));

        $summary = (new CatalogImporter())->apply($rows);

        $this->assertSame(1, $summary['updated']);
        $this->assertEquals(12.50, (float) $variation->fresh()->price);

        $fresh = $product->fresh();
        $this->assertSame('Renamed EN', $fresh->translate('en')->name);
        $this->assertSame('Renamed AR', $fresh->translate('ar')->name);
    }

    /** The rule the whole importer is built around. */
    public function test_a_supplier_product_is_skipped_and_left_byte_identical(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $product->external_source = 'yassen';
        $product->external_id = 'ext-1';
        $product->save();

        $before = $variation->fresh()->toArray();

        $rows = $this->parse($this->csv([
            $this->rowFor($product, $variation, ['price' => '999.00', 'product_name_en' => 'Hijacked']),
        ]));

        $plan = (new CatalogImporter())->plan($rows);
        $this->assertSame(CatalogImporter::SKIPPED, $plan[0]['action']);
        $this->assertStringContainsString('yassen', $plan[0]['reason']);

        $summary = (new CatalogImporter())->apply($rows);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['updated']);
        $this->assertEquals($before['price'], $variation->fresh()->price);
        $this->assertSame('Original EN', $product->fresh()->translate('en')->name);
    }

    public function test_a_new_product_and_variation_are_created(): void
    {
        $subcategory = $this->subcategory();
        $slug = 'new-' . uniqid();

        $rows = $this->parse($this->csv([[
            'product_slug' => $slug,
            'category_slug' => $subcategory->category->slug,
            'subcategory_slug' => $subcategory->slug,
            'product_type_id' => 2,
            'product_is_active' => 1,
            'product_name_en' => 'Brand new',
            'product_name_ar' => 'جديد',
            'variation_slug' => $slug . '-v1',
            'cost_price' => '4.00',
            'price' => '6.00',
            'variation_is_active' => 1,
        ]]));

        $summary = (new CatalogImporter())->apply($rows);

        $this->assertSame(1, $summary['created']);

        $product = Product::withoutGlobalScope('cms_draft_flag')->where('slug', $slug)->first();
        $this->assertNotNull($product);
        $this->assertNull($product->external_source, 'an imported product is never supplier-owned');
        $this->assertSame('جديد', $product->translate('ar')->name);

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')->where('slug', $slug . '-v1')->first();
        $this->assertNotNull($variation);
        $this->assertEquals(6.00, (float) $variation->price);
        $this->assertSame($product->id, $variation->product_id);
    }

    public function test_a_bad_row_fails_alone(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $rows = $this->parse($this->csv([
            ['product_slug' => 'ghost-' . uniqid(), 'subcategory_slug' => 'does-not-exist', 'variation_slug' => 'x'],
            $this->rowFor($product, $variation, ['price' => '21.00']),
        ]));

        $plan = (new CatalogImporter())->plan($rows);

        $this->assertSame(CatalogImporter::ERROR, $plan[0]['action']);
        $this->assertSame(CatalogImporter::UPDATE, $plan[1]['action']);

        $summary = (new CatalogImporter())->apply($rows);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['updated']);
        $this->assertEquals(21.00, (float) $variation->fresh()->price);
    }

    public function test_a_blank_cell_leaves_the_value_alone(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $rows = $this->parse($this->csv([
            $this->rowFor($product, $variation, ['price' => '', 'cost_price' => '']),
        ]));

        (new CatalogImporter())->apply($rows);

        $this->assertEquals(10.00, (float) $variation->fresh()->price);
        $this->assertEquals(5.00, (float) $variation->fresh()->cost_price);
    }

    public function test_export_downloads_a_csv_with_the_shared_header(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get('/' . config('hellotree.cms_route_prefix') . '/catalog-import/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString(implode(',', array_slice(CatalogCsv::header(), 0, 3)), $body);
    }

    public function test_preview_stages_the_file_without_writing(): void
    {
        $subcategory = $this->subcategory();
        [$product, $variation] = $this->manualProduct($subcategory);

        $csv = $this->csv([$this->rowFor($product, $variation, ['price' => '77.00'])]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/' . config('hellotree.cms_route_prefix') . '/catalog-import/preview', [
                'file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv),
            ])
            ->assertOk()
            ->assertSee('to update', false);

        $this->assertEquals(10.00, (float) $variation->fresh()->price, 'preview must not write');
    }

    public function test_commit_refuses_a_path_outside_the_staging_directory(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/' . config('hellotree.cms_route_prefix') . '/catalog-import', [
                'path' => '../../.env',
                'hash' => str_repeat('a', 64),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_the_page_requires_an_admin(): void
    {
        $this->get('/' . config('hellotree.cms_route_prefix') . '/catalog-import')->assertRedirect();
    }
}
