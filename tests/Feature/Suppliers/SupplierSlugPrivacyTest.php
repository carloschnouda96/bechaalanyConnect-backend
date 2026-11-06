<?php

namespace Tests\Feature\Suppliers;

use App\Models\User;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/** Regression from security audit run-5: public slugs carried the hidden supplier id. */
class SupplierSlugPrivacyTest extends TestCase
{
    use CreatesCatalog;

    private function legacyVariation(string $externalId): ProductsVariation
    {
        $variation = $this->createVariation(10);
        $variation->forceFill([
            'external_source' => 'swift',
            'external_id' => $externalId,
            'slug' => 'netflix-1-month-' . $externalId,
        ])->save();
        $variation->product->forceFill([
            'external_source' => 'swift',
            'external_id' => $externalId,
            'slug' => 'netflix-' . Str::lower(Str::random(6)) . '-' . $externalId,
        ])->save();

        return $variation->fresh();
    }

    public function test_the_command_removes_the_supplier_id_from_stored_slugs(): void
    {
        $id = (string) random_int(100000, 999999);
        $variation = $this->legacyVariation($id);

        Artisan::call('suppliers:reslug');

        $this->assertStringNotContainsString($id, $variation->fresh()->slug);
        $this->assertStringNotContainsString($id, $variation->product->fresh()->slug);
        $this->assertStringStartsWith('netflix-1-month-', $variation->fresh()->slug);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $id = (string) random_int(100000, 999999);
        $variation = $this->legacyVariation($id);

        Artisan::call('suppliers:reslug', ['--dry-run' => true]);

        $this->assertStringEndsWith($id, $variation->fresh()->slug);
    }

    public function test_a_row_the_admin_named_differently_is_left_alone(): void
    {
        $variation = $this->createVariation(10);
        $variation->forceFill(['external_source' => 'swift', 'external_id' => '77', 'slug' => 'my-own-slug'])->save();

        Artisan::call('suppliers:reslug');

        $this->assertSame('my-own-slug', $variation->fresh()->slug);
    }

    public function test_categories_created_by_the_sync_lose_the_id_too(): void
    {
        $id = (string) random_int(100000, 999999);
        $variation = $this->createVariation(10);
        $sub = $variation->product->subcategory;
        $cat = $sub->category;
        $sub->forceFill(['slug' => 'games-sub-' . $id])->save();
        $cat->forceFill(['slug' => 'games-cat-' . $id])->save();
        DB::table('supplier_categories')->insert([
            'source' => 'swift', 'external_id' => $id, 'name' => 'Games',
            'category_id' => $cat->id, 'subcategory_id' => $sub->id,
        ]);

        Artisan::call('suppliers:reslug');

        $this->assertStringNotContainsString($id, $sub->fresh()->slug);
        $this->assertStringNotContainsString($id, $cat->fresh()->slug);
    }
}
