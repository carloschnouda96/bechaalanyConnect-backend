<?php

namespace App\Console\Commands;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows created before SupplierCatalogSync::uniqueSlug() hashed its suffix still end in the
 * supplier's id, which the API otherwise hides. Product slugs are public URLs, so existing
 * links to those products stop working: this runs when the owner chooses, not in a migration.
 * A slug an admin changed no longer ends in the id and is left alone.
 */
class ReslugSupplierRows extends Command
{
    protected $signature = 'suppliers:reslug {--dry-run : List the changes without writing}';

    protected $description = 'Remove supplier ids from stored product and variation slugs';

    public function handle(): int
    {
        $changed = 0;

        foreach ([Product::class, ProductsVariation::class] as $model) {
            $rows = $model::withoutGlobalScopes()
                ->whereNotNull('external_source')
                ->whereNotNull('external_id')
                ->cursor();

            foreach ($rows as $row) {
                $new = $this->newSlug((string) $row->slug, (string) $row->external_id, (string) $row->external_source);
                if ($new === null) {
                    continue;
                }

                $changed++;
                $this->line(class_basename($model) . " #{$row->id}: {$row->slug} -> {$new}");

                if (!$this->option('dry-run')) {
                    $model::withoutGlobalScopes()->whereKey($row->id)->update(['slug' => $new]);
                }
            }
        }

        $changed += $this->reslugCategoryTree();

        $this->info(($this->option('dry-run') ? 'Would change ' : 'Changed ') . "{$changed} slug(s).");

        return self::SUCCESS;
    }

    /** The sync suffixes these with -cat-{id} and -sub-{id} of the supplier category. */
    private function reslugCategoryTree(): int
    {
        $changed = 0;

        foreach (DB::table('supplier_categories')->get() as $row) {
            foreach ([[Category::class, $row->category_id, 'cat-'], [Subcategory::class, $row->subcategory_id, 'sub-']] as [$model, $id, $prefix]) {
                $target = $id ? $model::withoutGlobalScopes()->find($id) : null;
                $new = $target ? $this->newSlug((string) $target->slug, $prefix . $row->external_id, (string) $row->source) : null;

                if ($new === null) {
                    continue;
                }

                $changed++;
                $this->line(class_basename($model) . " #{$id}: {$target->slug} -> {$new}");

                if (!$this->option('dry-run')) {
                    $model::withoutGlobalScopes()->whereKey($id)->update(['slug' => $new]);
                }
            }
        }

        return $changed;
    }

    private function newSlug(string $slug, string $externalId, string $source): ?string
    {
        // Grouped products are keyed group:{category id}, and their slug carries the category id.
        $suffix = '-' . Str::slug(Str::after($externalId, 'group:'));

        if ($suffix === '-' || !str_ends_with($slug, $suffix)) {
            return null;
        }

        $base = substr($slug, 0, -strlen($suffix));

        return $base . '-' . substr(hash_hmac('sha256', $source . '|' . $externalId, (string) config('app.key')), 0, 12);
    }
}
