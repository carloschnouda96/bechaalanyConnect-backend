<?php

namespace App\Services\Yassen;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use App\SupplierCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Imports the Yassen-Card catalog into the local product tables and keeps
 * prices in sync.
 *
 * Flow:
 *   1. Pull the full products feed.
 *   2. Discover supplier categories from the feed (parent_id / category_name)
 *      and upsert them into `supplier_categories`, preserving the admin's
 *      `import_enabled` toggle.
 *   3. For every product in an enabled category: ensure a local
 *      Category → Subcategory exists, then upsert the Product + its single
 *      ProductsVariation. The supplier price is stored as the variation
 *      `cost_price`/`external_price`; the selling `price` is
 *      cost * (1 + profit%), profit being the per-product override or the
 *      global default.
 *   4. Products that are unavailable, or that belong to a category whose import
 *      was turned off, are deactivated (is_active = 0) — never deleted, so order
 *      history survives and they re-activate on the next sync.
 *
 * Idempotent and safe to re-run.
 */
class YassenCatalogSync
{
    public const SOURCE = 'yassen';

    public function __construct(private YassenClient $client)
    {
    }

    /**
     * @return array{categories:int,created:int,updated:int,price_changed:int,deactivated:int,skipped:int,errors:int}
     */
    public function sync(bool $categoriesOnly = false): array
    {
        $summary = [
            'categories' => 0, 'created' => 0, 'updated' => 0,
            'price_changed' => 0, 'deactivated' => 0, 'skipped' => 0, 'errors' => 0,
        ];

        $products = $this->extractList($this->client->products());

        // 1 + 2. Discover & upsert supplier categories from the feed.
        $summary['categories'] = $this->discoverCategories($products);

        if ($categoriesOnly) {
            return $summary;
        }

        $enabled = SupplierCategory::where('source', self::SOURCE)
            ->where('import_enabled', true)
            ->get()
            ->keyBy('external_id');

        foreach ($products as $raw) {
            $categoryExternalId = (string) ($raw['parent_id'] ?? '');
            $supplierCategory = $enabled->get($categoryExternalId);

            if (!$supplierCategory) {
                $summary['skipped']++;
                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->upsertProduct($raw, $supplierCategory));
                $summary[$result['status']]++;
                if ($result['price_changed']) {
                    $summary['price_changed']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::error('Yassen product sync failed', [
                    'product_id' => $raw['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Deactivate imported products no longer offered / in disabled categories.
        $summary['deactivated'] = $this->deactivateStale($products, $enabled);

        return $summary;
    }

    private function discoverCategories(array $products): int
    {
        $seen = [];
        foreach ($products as $raw) {
            $externalId = (string) ($raw['parent_id'] ?? '');
            if ($externalId === '' || isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            $existing = SupplierCategory::where('source', self::SOURCE)
                ->where('external_id', $externalId)
                ->first();

            $attributes = [
                'name' => $raw['category_name'] ?? ('Category ' . $externalId),
                'image' => $raw['category_img'] ?? null,
            ];

            if ($existing) {
                // Never clobber the admin's import_enabled / mapping; only refresh metadata.
                $existing->fill($attributes)->save();
            } else {
                SupplierCategory::create(array_merge($attributes, [
                    'source' => self::SOURCE,
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
    private function upsertProduct(array $raw, SupplierCategory $supplierCategory): array
    {
        $externalId = (string) $raw['id'];
        $name = trim((string) ($raw['name'] ?? ('Product ' . $externalId)));
        $cost = (float) ($raw['price'] ?? 0);
        $available = ($raw['available'] ?? true) ? 1 : 0;
        $yassenType = (string) ($raw['product_type'] ?? 'package');

        [$category, $subcategory] = $this->ensureLocalTree($supplierCategory);

        $product = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        $isNew = $product === null;
        if ($isNew) {
            $product = new Product();
            $product->external_source = self::SOURCE;
            $product->external_id = $externalId;
            $product->profit_percentage = null; // null → global default
            $product->slug = $this->uniqueSlug('products', $name, $externalId);
        }

        $product->subcategory_id = $subcategory->id;
        $product->product_type_id = $this->resolveProductTypeId($raw);
        $product->is_active = $available;
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
            $variation->slug = $this->uniqueSlug('products_variations', $name, $externalId);
        }

        $profit = $product->effectiveProfitPercentage();
        $newPrice = ProductsVariation::computeSellingPrice($cost, $profit);
        $priceChanged = abs((float) $variation->price - $newPrice) > 0.0001
            || abs((float) $variation->external_price - $cost) > 0.0001;

        $variation->cost_price = $cost;
        $variation->external_price = $cost;
        $variation->price = $newPrice;
        $variation->external_type = $yassenType;
        $variation->external_qty_values = $this->normalizeQtyValues($raw['qty_values'] ?? null);
        $variation->is_active = $available;
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
    private function ensureLocalTree(SupplierCategory $supplierCategory): array
    {
        $name = $supplierCategory->name ?: ('Category ' . $supplierCategory->external_id);

        $category = $supplierCategory->category_id
            ? Category::withoutGlobalScope('cms_draft_flag')->find($supplierCategory->category_id)
            : null;

        if (!$category) {
            $category = new Category();
            $category->slug = $this->uniqueSlug('categories', $name, 'cat-' . $supplierCategory->external_id);
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
            $subcategory->slug = $this->uniqueSlug('subcategories', $name, 'sub-' . $supplierCategory->external_id);
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
     * unavailable, or belong to a category whose import was disabled.
     */
    private function deactivateStale(array $products, $enabled): int
    {
        $activeExternalIds = [];
        foreach ($products as $raw) {
            $categoryExternalId = (string) ($raw['parent_id'] ?? '');
            $available = ($raw['available'] ?? true);
            if ($enabled->has($categoryExternalId) && $available) {
                $activeExternalIds[] = (string) $raw['id'];
            }
        }

        $query = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', self::SOURCE)
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

    /**
     * Pick the local product_type_id (which drives the storefront purchase form)
     * from the supplier's `params` — the list of inputs the order requires. A
     * product that needs no input is a code/card (Recharge By Code = 2); one that
     * asks for a phone number maps to Telecommunication Charge (3); anything else
     * that needs an identifier maps to Direct Recharge (1, shows the User ID
     * field = the Yassen `playerId`). Note: product_type "package"/"amount" alone
     * does NOT indicate this — e.g. a PUBG "package" still needs a player id.
     */
    private function resolveProductTypeId(array $raw): int
    {
        $params = $raw['params'] ?? [];
        if (empty($params)) {
            return 2; // Recharge By Code — no input required
        }

        $text = mb_strtolower(implode(' ', (array) $params));
        $phoneHints = ['هاتف', 'جوال', 'موبايل', 'phone', 'mobile'];
        foreach ($phoneHints as $hint) {
            if (mb_strpos($text, $hint) !== false) {
                return 3; // Telecommunication Charge — phone number field
            }
        }

        return 1; // Direct Recharge — User ID / player id field
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

    private function uniqueSlug(string $table, string $name, string $suffix): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'yassen';
        }
        return $base . '-' . Str::slug($suffix);
    }

    /** Yassen list endpoints may wrap rows under data/products; normalise to a flat list. */
    private function extractList($response): array
    {
        if (!is_array($response)) {
            return [];
        }
        foreach (['data', 'products', 'result', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }
        // Already a flat list?
        return Arr::isList($response) ? $response : [$response];
    }
}
