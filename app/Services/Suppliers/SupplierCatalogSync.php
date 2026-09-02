<?php

namespace App\Services\Suppliers;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Services\Suppliers\Contracts\PartialCatalogAware;
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
 * GROUPED CATEGORIES
 * ------------------
 * One product per supplier row is right for a catalog of independent services,
 * and wrong for a category that is really one product sold in several sizes —
 * usharez's quota bundles imported "5.5 GB" … "45 GB" as six separate storefront
 * products instead of six entries in one product's amount dropdown.
 *
 * Ticking `supplier_categories.group_as_single_product` switches step 3 to
 * maintaining ONE grouped product for the category, with every supplier row
 * attached to it as a variation (the storefront already renders one dropdown
 * entry per active variation). The flag is per category and defaults off, so
 * every existing category behaves exactly as before.
 *
 * Idempotent and safe to re-run, and safe to flip in either direction: a
 * variation is matched by (source, external_id) rather than by parent, so
 * turning grouping on or off MOVES the existing row instead of duplicating it —
 * which keeps its id, and with it every `orders.product_variation_id` pointing
 * at it.
 */
class SupplierCatalogSync
{
    /**
     * Grouped products resolved during the current sync() call, keyed by
     * supplier_categories.id, so a category with 200 rows resolves its product once.
     *
     * @var array<int,Product>
     */
    private array $groupProducts = [];

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
        $this->groupProducts = [];
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
        // A multi-endpoint connector reports which slice of its catalog it could
        // actually reach, so an endpoint outage isn't read as a withdrawal.
        $scopes = $connector instanceof PartialCatalogAware ? $connector->catalogScopes() : null;
        $summary['deactivated'] = $this->deactivateStale($products, $enabled, $source, $scopes);

        // 5. Grouped categories keep their withdrawn rows as deactivated variations
        // of the shared product rather than as deactivated products.
        $summary['deactivated'] += $this->reconcileGroupedCategories($products, $enabled, $source, $scopes);

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

    /** Synthetic product id for a grouped category. Never collides with a real supplier id. */
    public static function groupExternalId(SupplierCategory $supplierCategory): string
    {
        return 'group:' . $supplierCategory->external_id;
    }

    /**
     * @return array{status:string,price_changed:bool}
     */
    private function upsertProduct(SupplierProduct $dto, SupplierCategory $supplierCategory, string $source): array
    {
        if ($supplierCategory->group_as_single_product) {
            return $this->upsertGroupedVariation($dto, $supplierCategory, $source);
        }

        $externalId = $dto->externalId;
        $name = trim($dto->name) ?: ('Product ' . $externalId);

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
            // Seeded once, on create only. Most suppliers offer no per-product image
            // (Yassen exposes only a category image; Swift/1xpanel/usharez none at
            // all), so this is usually null and the storefront shows its placeholder
            // until an admin uploads one on the CMS products page.
            $product->image = $dto->image;
        }

        // Excluded products (admin's "except Netflix…" switch) stay inactive even
        // when the supplier still offers them.
        $excluded = (bool) ($product->import_excluded ?? false);
        $active = self::isActiveAfterImport($dto->available, $excluded);

        // NOTE: $product->image is deliberately not touched on update — an admin's
        // CMS upload must survive every re-sync. See ensureLocalTree() for the same
        // rule applied to categories.
        $product->subcategory_id = $subcategory->id;
        $product->product_type_id = $dto->productTypeId;
        $product->is_active = $active;
        $product->cms_draft_flag = 0;
        $this->setTranslations($product, ['name' => $name, 'description' => '']);
        $product->save();

        // Single variation per supplier product.
        $variation = $this->upsertVariation($product, $dto, $source, $active, false);

        return [
            'status' => $isNew ? 'created' : 'updated',
            'price_changed' => $variation['price_changed'] && !$isNew,
        ];
    }

    /**
     * Grouped category: every supplier row becomes a variation of the one product
     * this category maintains, so the storefront renders them as entries in a
     * single amount dropdown instead of as separate products.
     *
     * "created" here counts new VARIATIONS, not new products — the product is
     * created once and the variations are the unit of work from then on.
     *
     * @return array{status:string,price_changed:bool}
     */
    private function upsertGroupedVariation(SupplierProduct $dto, SupplierCategory $supplierCategory, string $source): array
    {
        [$category, $subcategory] = $this->ensureLocalTree($supplierCategory, $source);

        $product = $this->ensureGroupProduct($supplierCategory, $dto, $subcategory, $source);

        $excluded = (bool) ($product->import_excluded ?? false);
        $active = self::isActiveAfterImport($dto->available, $excluded);

        $variation = $this->upsertVariation($product, $dto, $source, $active, true);

        return [
            'status' => $variation['created'] ? 'created' : 'updated',
            'price_changed' => $variation['price_changed'] && !$variation['created'],
        ];
    }

    /**
     * Resolve (creating on first sync) the single product a grouped category
     * imports into.
     *
     * `external_source` + `external_id` are load-bearing, not bookkeeping:
     * SupplierOrderFulfillment::fulfill() resolves the connector from
     * `$variation->product->external_source` and returns early when
     * `$variation->product->external_id` is null. A grouped product missing either
     * would let the CMS approve orders that are then never placed at the supplier —
     * silently, with no refund.
     *
     * Everything an admin can see is seeded ONCE and never re-written: name,
     * product type and image belong to whoever curates the storefront, exactly as
     * `$product->image` already does on the per-product path. Only `subcategory_id`
     * is re-asserted, so re-mapping the category in the CMS still moves the product.
     */
    private function ensureGroupProduct(
        SupplierCategory $supplierCategory,
        SupplierProduct $dto,
        Subcategory $subcategory,
        string $source
    ): Product {
        if (isset($this->groupProducts[$supplierCategory->id])) {
            return $this->groupProducts[$supplierCategory->id];
        }

        $externalId = self::groupExternalId($supplierCategory);

        $product = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', $externalId)
            ->first();

        if ($product && $product->external_source !== $source) {
            // Two suppliers claiming one synthetic id would make the fulfilment
            // lookup resolve the wrong connector. Refuse rather than hijack.
            throw new \RuntimeException(sprintf(
                'Grouped product %s already belongs to supplier "%s"; refusing to claim it for "%s".',
                $externalId,
                (string) $product->external_source,
                $source
            ));
        }

        if (!$product) {
            $name = trim((string) $supplierCategory->name) ?: ('Category ' . $supplierCategory->external_id);

            $product = new Product();
            $product->external_source = $source;
            $product->external_id = $externalId;
            $product->profit_percentage = null; // null → global default
            // Suffixed with the supplier category id, not the synthetic product id —
            // same uniqueness, without "group" turning up in a customer-facing URL.
            $product->slug = $this->uniqueSlug($name, $supplierCategory->external_id, $source);
            // The supplier category's artwork is the only image a grouped product
            // can be seeded with — its rows are sizes of one thing, not distinct
            // products. An admin's CMS upload replaces it permanently.
            $product->image = $supplierCategory->image;
            // Seeded from the first row; every row in a grouped category collects
            // the same recipient, so they always agree.
            $product->product_type_id = $dto->productTypeId;
            $product->is_active = 1;
            $this->setTranslations($product, ['name' => $name, 'description' => '']);
        }

        $product->subcategory_id = $subcategory->id;
        $product->cms_draft_flag = 0;
        $product->save();

        return $this->groupProducts[$supplierCategory->id] = $product;
    }

    /**
     * Upsert the variation carrying one supplier row's price and availability.
     *
     * The lookup is by (source, external_id) and NOT by parent product on purpose:
     * that is what lets a variation MOVE onto the grouped product when a category's
     * grouping is switched on — and back off again — instead of being duplicated.
     * It keeps its id, so every `orders.product_variation_id` already pointing at it
     * stays valid and order history survives the switch.
     *
     * @return array{created:bool,price_changed:bool}
     */
    private function upsertVariation(
        Product $product,
        SupplierProduct $dto,
        string $source,
        int $active,
        bool $grouped
    ): array {
        $externalId = $dto->externalId;
        $name = trim($dto->name) ?: ('Product ' . $externalId);
        $cost = $dto->unitCost;

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', $externalId)
            ->whereHas('product', fn ($q) => $q->withoutGlobalScope('cms_draft_flag')
                ->where('external_source', $source))
            ->first();

        if (!$variation) {
            $variation = $this->reclaimOrphanVariation($externalId, $source);
        }

        $isNew = $variation === null;
        if ($isNew) {
            $variation = new ProductsVariation();
            $variation->external_id = $externalId;
            $variation->slug = $this->uniqueSlug($name, $externalId, $source);
        }

        $variation->product_id = $product->id;

        // The storefront orders the dropdown by ht_pos, and a synced row is
        // otherwise NULL — which leaves the order to MySQL. Appending gives a
        // stable feed order that an admin can still re-drag in the CMS. Applied to
        // re-parented rows too, or a category grouped after its first import would
        // keep half its dropdown unordered.
        if ($grouped && $variation->ht_pos === null) {
            $variation->ht_pos = 1 + (int) ProductsVariation::withoutGlobalScope('cms_draft_flag')
                ->where('product_id', $product->id)
                ->max('ht_pos');
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

        return ['created' => $isNew, 'price_changed' => $priceChanged];
    }

    /**
     * Take back a supplier variation that was moved onto a product no supplier owns.
     *
     * Admins did this by hand to fake a variation dropdown before grouping existed
     * (moving "16.5 GB" onto "Alfa Direct Recharge" in the CMS). The move is worse
     * than cosmetic: SupplierOrderFulfillment::fulfill() bails when the parent
     * product has no `external_id`, so an order for that variation is charged to
     * the customer and then never placed at the supplier — silently, with no refund.
     * And leaving it there would make the next sync create a second copy under the
     * supplier's own product, so the storefront would sell the same bundle twice
     * with only one of them working.
     *
     * Scoped to parents with a NULL `external_source`: a product belonging to
     * ANOTHER supplier is that supplier's business, never ours to take.
     */
    private function reclaimOrphanVariation(string $externalId, string $source): ?ProductsVariation
    {
        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('external_id', $externalId)
            ->whereHas('product', fn ($q) => $q->withoutGlobalScope('cms_draft_flag')
                ->whereNull('external_source'))
            ->first();

        if ($variation) {
            Log::warning('Reclaimed a supplier variation from a non-supplier product', [
                'source' => $source,
                'external_id' => $externalId,
                'variation_id' => $variation->id,
                'from_product_id' => $variation->product_id,
            ]);
        }

        return $variation;
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
        } elseif ($this->shouldRefreshImage($category->image)) {
            $category->image = $supplierCategory->image;
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
        } elseif ($this->shouldRefreshImage($subcategory->image)) {
            $subcategory->image = $supplierCategory->image;
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
     * @param string[]|null $scopes external_id prefixes the connector vouched for
     *                              (see PartialCatalogAware); null = whole catalog
     */
    private function deactivateStale(array $products, $enabled, string $source, ?array $scopes = null): int
    {
        $activeExternalIds = [];
        foreach ($products as $dto) {
            $supplierCategory = $enabled->get($dto->categoryExternalId);
            // A grouped category's rows live as variations, so their ids no longer
            // name a product. Leaving them in the keep-set is what would strand the
            // per-row product shells from before grouping was turned on, active but
            // childless — this is what retires them on the first grouped sync.
            if ($supplierCategory && !$supplierCategory->group_as_single_product && $dto->available) {
                $activeExternalIds[] = $dto->externalId;
            }
        }

        // Grouped products are keyed by a synthetic id that never appears in a feed,
        // so nothing else can keep them alive here. Their activation follows their
        // variations instead — see reconcileGroupedCategories().
        foreach ($enabled as $supplierCategory) {
            if ($supplierCategory->group_as_single_product) {
                $activeExternalIds[] = self::groupExternalId($supplierCategory);
            }
        }

        // The connector assembles its catalog from several endpoints and reached
        // none of them this run — it can't vouch for anything, so touch nothing.
        if ($scopes === []) {
            return 0;
        }

        $query = Product::withoutGlobalScope('cms_draft_flag')
            ->where('external_source', $source)
            ->where('is_active', 1);

        // Limit deactivation to the families actually fetched; products outside
        // them were simply not observed, not withdrawn.
        if (!empty($scopes)) {
            $query->where(function ($q) use ($scopes) {
                foreach ($scopes as $prefix) {
                    $q->orWhere('external_id', 'like', $prefix . '%');
                }
            });
        }

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
     * The grouped equivalent of deactivateStale(): in a grouped category a
     * withdrawn supplier row is a dead VARIATION of a still-live product, not a
     * dead product, so it needs its own sweep.
     *
     * The grouped product then simply follows its variations — live while any of
     * them is, dark once the supplier has withdrawn the lot — which also means it
     * comes back on its own when the supplier restocks. `import_excluded` still
     * overrides, through the same isActiveAfterImport() rule as everywhere else.
     *
     * @param SupplierProduct[] $products
     * @param string[]|null $scopes external_id prefixes the connector vouched for
     */
    private function reconcileGroupedCategories(array $products, $enabled, string $source, ?array $scopes = null): int
    {
        // Nothing reachable this run — see deactivateStale().
        if ($scopes === []) {
            return 0;
        }

        $count = 0;

        foreach ($enabled as $supplierCategory) {
            if (!$supplierCategory->group_as_single_product) {
                continue;
            }

            $product = Product::withoutGlobalScope('cms_draft_flag')
                ->where('external_source', $source)
                ->where('external_id', self::groupExternalId($supplierCategory))
                ->first();

            if (!$product) {
                continue; // never synced (category enabled after the last run)
            }

            $activeExternalIds = [];
            foreach ($products as $dto) {
                if ($dto->categoryExternalId === $supplierCategory->external_id && $dto->available) {
                    $activeExternalIds[] = $dto->externalId;
                }
            }

            $query = ProductsVariation::withoutGlobalScope('cms_draft_flag')
                ->where('product_id', $product->id)
                ->where('is_active', 1)
                // A hand-added variation on a grouped product is the admin's, not
                // the sync's, and is never deactivated by a supplier's silence.
                ->whereNotNull('external_id');

            // Same rule as the product sweep: only families the connector actually
            // reached may be treated as withdrawn.
            if (!empty($scopes)) {
                $query->where(function ($q) use ($scopes) {
                    foreach ($scopes as $prefix) {
                        $q->orWhere('external_id', 'like', $prefix . '%');
                    }
                });
            }

            if (!empty($activeExternalIds)) {
                $query->whereNotIn('external_id', $activeExternalIds);
            }

            foreach ($query->get() as $variation) {
                $variation->is_active = 0;
                $variation->save();
                $count++;
            }

            $hasActiveVariation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
                ->where('product_id', $product->id)
                ->where('is_active', 1)
                ->exists();

            $desired = self::isActiveAfterImport($hasActiveVariation, (bool) $product->import_excluded);
            if ((int) $product->is_active !== $desired) {
                $product->is_active = $desired;
                $product->save();
            }
        }

        return $count;
    }

    /**
     * May the sync overwrite this image with the supplier's current one?
     *
     * Only when it is empty or is itself a supplier URL. A local disk path means an
     * admin uploaded the image on the CMS page, and that choice is permanent — it is
     * the whole point of the CMS image field on imported rows.
     */
    private function shouldRefreshImage(?string $current): bool
    {
        $current = trim((string) $current);

        return $current === '' || Str::startsWith($current, ['http://', 'https://', '//']);
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
