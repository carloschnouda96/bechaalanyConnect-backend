<?php

namespace App\Services\Suppliers;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\SupplierConnector;
use App\Subcategory;
use App\SupplierCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports any supplier's catalog into the local product tables and keeps prices
 * in sync. Supplier-agnostic: it speaks only the normalised SupplierProduct DTO
 * vocabulary produced by the connector's fetchCatalog(), so the same engine
 * drives Yassen, Swift, and any future supplier.
 *
 * Flow (per connector):
 *   1. Pull the normalised catalog.
 *   2. Discover supplier categories and upsert them into `supplier_categories`,
 *      preserving the admin's per-category `import_enabled` toggle.
 *   3. For every product in an enabled category: ensure a local
 *      Category → Subcategory exists, then upsert the Product + its single
 *      ProductsVariation. The supplier unit cost is stored as the variation
 *      `cost_price`/`external_price`; selling `price` = cost * (1 + profit%).
 *   4. Products that are unavailable, in a disabled category, no longer offered,
 *      or flagged `import_excluded` are deactivated (is_active = 0) — never
 *      deleted, so order history survives.
 *
 * Idempotent and safe to re-run.
 */
class SupplierCatalogSync
{
    /**
     * Selling price is suppressed (is_active = 0) when the supplier doesn't offer
     * the product OR the admin flagged it as excluded from import. This is the
     * single rule the "except Netflix/Shahid/OSN+/Anghami" switch relies on.
     */
    public static function isActiveAfterImport(bool $available, bool $excluded): int
    {
        return ($available && !$excluded) ? 1 : 0;
    }

    /**
     * @return array{categories:int,created:int,updated:int,price_changed:int,deactivated:int,skipped:int,errors:int}
     */
    public function sync(SupplierConnector $connector, bool $categoriesOnly = false): array
    {
        $source = $connector->key();
        $summary = [
            'categories' => 0, 'created' => 0, 'updated' => 0,
            'price_changed' => 0, 'deactivated' => 0, 'skipped' => 0, 'errors' => 0,
        ];

        /** @var SupplierProduct[] $products */
        $products = $connector->fetchCatalog();

        // 1 + 2. Discover & upsert supplier categories from the feed.
        $summary['categories'] = $this->discoverCategories($products, $source);

        if ($categoriesOnly) {
            return $summary;
        }

        $enabled = SupplierCategory::where('source', $source)
            ->where('import_enabled', true)
            ->get()
            ->keyBy('external_id');

        foreach ($products as $dto) {
            $supplierCategory = $enabled->get($dto->categoryExternalId);

            if (!$supplierCategory) {
                $summary['skipped']++;
                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->upsertProduct($dto, $supplierCategory, $source));
                $summary[$result['status']]++;
                if ($result['price_changed']) {
                    $summary['price_changed']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::error('Supplier product sync failed', [
                    'source' => $source,
                    'external_id' => $dto->externalId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Deactivate imported products no longer offered / in disabled categories.
        $summary['deactivated'] = $this->deactivateStale($products, $enabled, $source);

        return $summary;
    }

    /**
     * @param SupplierProduct[] $products
     */
    private function discoverCategories(array $products, string $source): int
    {
        $seen = [];
        foreach ($products as $dto) {
            $externalId = $dto->categoryExternalId;
            if ($externalId === '' || isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            $existing = SupplierCategory::where('source', $source)
                ->where('external_id', $externalId)
                ->first();

            $attributes = [
                'name' => $dto->categoryName ?: ('Category ' . $externalId),
                'image' => $dto->categoryImage,
            ];

            if ($existing) {
                // Never clobber the admin's import_enabled / mapping; only refresh metadata.
                $existing->fill($attributes)->save();
            } else {
                SupplierCategory::create(array_merge($attributes, [
                    'source' => $source,
                    'external_id' => $externalId,
                    'import_enabled' => false,
                    'cms_draft_flag' => 0,
                ]));
            }
        }

        return count($seen);
    }

    /**
     * @return array{status:string,price_changed:bool}
     */
    private function upsertProduct(SupplierProduct $dto, SupplierCategory $supplierCategory, string $source): array
    {
        $externalId = $dto->externalId;
        $name = trim($dto->name) ?: ('Product ' . $externalId);
        $cost = $dto->unitCost;

        [$category, $subcategory] = $this->ensureLocalTree($supplierCategory, $source);

        $product = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', $source)
            ->where('external_id', $externalId)
            ->first();

        $isNew = $product === null;
        if ($isNew) {
            $product = new Product();
            $product->external_source = $source;
            $product->external_id = $externalId;
            $product->profit_percentage = null; // null → global default
            $product->slug = $this->uniqueSlug($name, $externalId, $source);
        }

        // Excluded products (admin's "except Netflix…" switch) stay inactive even
        // when the supplier still offers them.
        $excluded = (bool) ($product->import_excluded ?? false);
        $active = self::isActiveAfterImport($dto->available, $excluded);

        $product->subcategory_id = $subcategory->id;
        $product->product_type_id = $dto->productTypeId;
        $product->is_active = $active;
        $product->cms_draft_flag = 0;
        $this->setTranslations($product, ['name' => $name, 'description' => '']);
        $product->save();

        // Single variation per supplier product.
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', $externalId)
            ->where('product_id', $product->id)
            ->first();

        if (!$variation) {
            $variation = new ProductsVariation();
            $variation->product_id = $product->id;
            $variation->external_id = $externalId;
            $variation->slug = $this->uniqueSlug($name, $externalId, $source);
        }

        $profit = $product->effectiveProfitPercentage();
        $newPrice = ProductsVariation::computeSellingPrice($cost, $profit);
        $priceChanged = abs((float) $variation->price - $newPrice) > 0.0001
            || abs((float) $variation->external_price - $cost) > 0.0001;

        $variation->cost_price = $cost;
        $variation->external_price = $cost;
        $variation->price = $newPrice;
        $variation->external_type = $dto->externalType;
        $variation->external_qty_values = $this->normalizeQtyValues($dto->qtyValues);
        $variation->is_active = $active;
        $variation->cms_draft_flag = 0;
        $this->setTranslations($variation, ['name' => $name, 'description' => '']);
        $variation->save();

        return [
            'status' => $isNew ? 'created' : 'updated',
            'price_changed' => $priceChanged && !$isNew,
        ];
    }

    /**
     * Ensure a local Category + Subcategory exist for the supplier category and
     * cache their ids back onto the supplier_categories row.
     *
     * @return array{0:Category,1:Subcategory}
     */
    private function ensureLocalTree(SupplierCategory $supplierCategory, string $source): array
    {
        $name = $supplierCategory->name ?: ('Category ' . $supplierCategory->external_id);

        $category = $supplierCategory->category_id
            ? Category::withoutGlobalScope('cms_draft_flag')->find($supplierCategory->category_id)
            : null;

        if (!$category) {
            $category = new Category();
            $category->slug = $this->uniqueSlug($name, 'cat-' . $supplierCategory->external_id, $source);
            $category->image = $supplierCategory->image;
            $category->is_active = 1;
            $category->cms_draft_flag = 0;
            $this->setTranslations($category, ['title' => $name, 'description' => '']);
            $category->save();
        }

        $subcategory = $supplierCategory->subcategory_id
            ? Subcategory::withoutGlobalScope('cms_draft_flag')->find($supplierCategory->subcategory_id)
            : null;

        if (!$subcategory) {
            $subcategory = new Subcategory();
            $subcategory->category_id = $category->id;
            $subcategory->slug = $this->uniqueSlug($name, 'sub-' . $supplierCategory->external_id, $source);
            $subcategory->image = $supplierCategory->image;
            $subcategory->is_active = 1;
            $subcategory->cms_draft_flag = 0;
            $this->setTranslations($subcategory, ['title' => $name, 'description' => '']);
            $subcategory->save();
        }

        if ($supplierCategory->category_id !== $category->id || $supplierCategory->subcategory_id !== $subcategory->id) {
            $supplierCategory->category_id = $category->id;
            $supplierCategory->subcategory_id = $subcategory->id;
            $supplierCategory->save();
        }

        return [$category, $subcategory];
    }

    /**
     * Deactivate imported products that are no longer in the feed, are
     * unavailable, are excluded, or belong to a category whose import was
     * disabled. Excluded products are kept out of the active set so they never
     * reactivate.
     *
     * @param SupplierProduct[] $products
     */
    private function deactivateStale(array $products, $enabled, string $source): int
    {
        $activeExternalIds = [];
        foreach ($products as $dto) {
            if ($enabled->has($dto->categoryExternalId) && $dto->available) {
                $activeExternalIds[] = $dto->externalId;
            }
        }

        $query = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', $source)
            ->where('is_active', 1);

        if (!empty($activeExternalIds)) {
            $query->whereNotIn('external_id', $activeExternalIds);
        }

        $count = 0;
        foreach ($query->get() as $product) {
            $product->is_active = 0;
            $product->save();
            ProductsVariation::withoutGlobalScope('cms_draft_flag')
                ->where('product_id', $product->id)
                ->update(['is_active' => 0]);
            $count++;
        }

        return $count;
    }

    /** Normalise qty_values (null | list of amounts | {min,max}) for storage. */
    private function normalizeQtyValues($qtyValues): ?array
    {
        if ($qtyValues === null || $qtyValues === '') {
            return null;
        }
        if (is_array($qtyValues)) {
            return $qtyValues;
        }
        return ['value' => $qtyValues];
    }

    private function setTranslations($model, array $values): void
    {
        foreach ($this->locales() as $locale) {
            foreach ($values as $attr => $value) {
                $model->translateOrNew($locale)->{$attr} = $value;
            }
        }
    }

    private function locales(): array
    {
        try {
            $locales = \Hellotreedigital\Cms\Models\Language::pluck('slug')->filter()->all();
        } catch (\Throwable $e) {
            $locales = [];
        }
        return $locales ?: ['en', 'ar'];
    }

    private function uniqueSlug(string $name, string $suffix, string $source): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = $source;
        }
        return $base . '-' . Str::slug($suffix);
    }
}
