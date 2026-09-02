<?php

namespace App\Services\Cms;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Support\Facades\DB;

/**
 * Turns catalog CSV rows into a reviewed set of writes.
 *
 * Two phases on purpose. plan() reads and decides but writes nothing, so an admin sees
 * exactly what a file will do — including which rows it refuses — before anything
 * happens. apply() then performs only what was planned. A bulk catalog edit that cannot
 * be previewed is a bulk catalog accident.
 *
 * THE RULE THAT MATTERS: a product with an `external_source` is owned by its supplier
 * connector. Its name, price and availability are re-derived on every `{supplier}:sync`
 * from the supplier's own feed, and its selling price is recomputed from cost ×
 * profit% by Product::recalculateSupplierPrices(). Editing one here would be silently
 * reverted at the next sync, or would fight the markup engine until it was. Those rows
 * are reported as skipped, never written, and never created.
 */
class CatalogImporter
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    /** Columns an admin may set through the CSV, with their cast. */
    private const PRODUCT_COLUMNS = [
        'product_type_id' => 'int',
        'product_is_active' => 'bool',
        'profit_percentage' => 'decimal',
        'import_excluded' => 'bool',
    ];

    private const VARIATION_COLUMNS = [
        'cost_price' => 'float',
        'price' => 'decimal',
        'unit_amount' => 'int',
        'variation_is_active' => 'bool',
    ];

    /** product_ column => model attribute. */
    private const PRODUCT_ATTRIBUTE = [
        'product_type_id' => 'product_type_id',
        'product_is_active' => 'is_active',
        'profit_percentage' => 'profit_percentage',
        'import_excluded' => 'import_excluded',
    ];

    private const VARIATION_ATTRIBUTE = [
        'cost_price' => 'cost_price',
        'price' => 'price',
        'unit_amount' => 'unit_amount',
        'variation_is_active' => 'is_active',
    ];

    /**
     * Decide what each row would do. Writes nothing.
     *
     * @return array<int, array> one entry per input row
     */
    public function plan(array $rows): array
    {
        $locales = CatalogCsv::locales();
        $plan = [];

        // Slugs the file itself is about to create, so a second row naming the same new
        // product reads as "update the one being created", not "create it twice".
        $pendingProducts = [];

        foreach ($rows as $row) {
            $plan[] = $this->planRow($row, $locales, $pendingProducts);
        }

        return $plan;
    }

    private function planRow(array $row, array $locales, array &$pendingProducts): array
    {
        $line = $row['__line'] ?? null;
        $productSlug = (string) ($row['product_slug'] ?? '');
        $variationSlug = (string) ($row['variation_slug'] ?? '');

        $entry = [
            'line' => $line,
            'product_slug' => $productSlug,
            'variation_slug' => $variationSlug,
            'action' => self::ERROR,
            'reason' => null,
            'changes' => [],
        ];

        if ($productSlug === '') {
            $entry['reason'] = 'product_slug is empty.';

            return $entry;
        }

        $product = Product::withoutGlobalScope('cms_draft_flag')
            ->where('slug', $productSlug)
            ->first();

        if ($product && filled($product->external_source)) {
            $entry['action'] = self::SKIPPED;
            $entry['reason'] = "Owned by the '{$product->external_source}' supplier sync — edit its profit % on the product page instead.";

            return $entry;
        }

        $creatingProduct = !$product && !isset($pendingProducts[$productSlug]);

        // Resolve the placement before deciding anything, so a bad slug is an error on
        // this row rather than a half-applied product later.
        $subcategoryId = null;

        if (filled($row['subcategory_slug'] ?? null)) {
            $subcategory = $this->resolveSubcategory($row);

            if (!$subcategory) {
                $entry['reason'] = 'No subcategory "' . $row['subcategory_slug'] . '"'
                    . (filled($row['category_slug'] ?? null) ? ' under category "' . $row['category_slug'] . '"' : '')
                    . '.';

                return $entry;
            }

            $subcategoryId = $subcategory->id;
        }

        if ($creatingProduct && !$subcategoryId) {
            $entry['reason'] = 'A new product needs a subcategory_slug.';

            return $entry;
        }

        $changes = [];

        if ($creatingProduct) {
            $entry['action'] = self::CREATE;
            $changes['product'] = ['(new product)' => [null, $productSlug]];
            $pendingProducts[$productSlug] = true;
        } elseif ($product) {
            $productChanges = $this->diffProduct($product, $row, $subcategoryId, $locales);

            if ($productChanges) {
                $changes['product'] = $productChanges;
            }
        }

        if ($variationSlug !== '') {
            $variation = $product
                ? ProductsVariation::withoutGlobalScope('cms_draft_flag')
                    ->where('slug', $variationSlug)
                    ->first()
                : null;

            if ($variation && filled($variation->external_id)) {
                $entry['action'] = self::SKIPPED;
                $entry['reason'] = 'Variation is supplier-imported — its price is derived from cost × profit %.';

                return $entry;
            }

            if (!$variation) {
                $changes['variation'] = ['(new variation)' => [null, $variationSlug]];
            } else {
                $variationChanges = $this->diffVariation($variation, $row, $locales);

                if ($variationChanges) {
                    $changes['variation'] = $variationChanges;
                }
            }
        }

        $entry['changes'] = $changes;

        if ($entry['action'] !== self::CREATE) {
            $entry['action'] = $changes ? self::UPDATE : self::UNCHANGED;
        }

        return $entry;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> attribute => [from, to] */
    private function diffProduct(Product $product, array $row, ?int $subcategoryId, array $locales): array
    {
        $changes = [];

        if ($subcategoryId && (int) $product->subcategory_id !== $subcategoryId) {
            $changes['subcategory_id'] = [$product->subcategory_id, $subcategoryId];
        }

        foreach (self::PRODUCT_COLUMNS as $column => $cast) {
            if ($this->absent($row, $column)) {
                continue;
            }

            $attribute = self::PRODUCT_ATTRIBUTE[$column];
            $new = $this->cast($row[$column], $cast);
            $old = $product->{$attribute};

            if (!$this->same($old, $new, $cast)) {
                $changes[$attribute] = [$old, $new];
            }
        }

        foreach (['product_name' => 'name', 'product_description' => 'description'] as $column => $attribute) {
            foreach ($locales as $locale) {
                $key = $column . '_' . $locale;

                if ($this->absent($row, $key)) {
                    continue;
                }

                $old = (string) optional($product->translate($locale))->{$attribute};

                if ($old !== (string) $row[$key]) {
                    $changes[$attribute . ' (' . $locale . ')'] = [$old, $row[$key]];
                }
            }
        }

        return $changes;
    }

    private function diffVariation(ProductsVariation $variation, array $row, array $locales): array
    {
        $changes = [];

        foreach (self::VARIATION_COLUMNS as $column => $cast) {
            if ($this->absent($row, $column)) {
                continue;
            }

            $attribute = self::VARIATION_ATTRIBUTE[$column];
            $new = $this->cast($row[$column], $cast);
            $old = $variation->{$attribute};

            if (!$this->same($old, $new, $cast)) {
                $changes[$attribute] = [$old, $new];
            }
        }

        $translated = [
            'variation_name' => 'name',
            'variation_description' => 'description',
            'variation_unit_label' => 'unit_label',
        ];

        foreach ($translated as $column => $attribute) {
            foreach ($locales as $locale) {
                $key = $column . '_' . $locale;

                if ($this->absent($row, $key)) {
                    continue;
                }

                $old = (string) optional($variation->translate($locale))->{$attribute};

                if ($old !== (string) $row[$key]) {
                    $changes[$attribute . ' (' . $locale . ')'] = [$old, $row[$key]];
                }
            }
        }

        return $changes;
    }

    /**
     * Perform the writes the plan describes.
     *
     * One transaction for the whole file: a catalog half-updated because row 400 was
     * malformed is worse than one not updated at all, and the admin has already seen
     * the errors in the preview.
     *
     * @return array{created: int, updated: int, skipped: int, unchanged: int}
     */
    public function apply(array $rows): array
    {
        $locales = CatalogCsv::locales();
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($rows, $locales, &$summary) {
            $pendingProducts = [];

            foreach ($rows as $row) {
                $entry = $this->planRow($row, $locales, $pendingProducts);

                if (in_array($entry['action'], [self::SKIPPED, self::ERROR], true)) {
                    $summary['skipped']++;
                    continue;
                }

                if ($entry['action'] === self::UNCHANGED) {
                    $summary['unchanged']++;
                    continue;
                }

                $created = $this->write($row, $locales);
                $summary[$created ? 'created' : 'updated']++;
            }
        });

        return $summary;
    }

    /** @return bool whether a product was created */
    private function write(array $row, array $locales): bool
    {
        $productSlug = (string) $row['product_slug'];

        $product = Product::withoutGlobalScope('cms_draft_flag')->where('slug', $productSlug)->first();
        $created = false;

        if (!$product) {
            $subcategory = $this->resolveSubcategory($row);

            $product = new Product();
            $product->slug = $productSlug;
            $product->subcategory_id = $subcategory->id;
            $product->product_type_id = $this->cast($row['product_type_id'] ?? null, 'int') ?? 1;
            $product->is_active = $this->cast($row['product_is_active'] ?? null, 'bool') ?? 0;
            $created = true;
        } elseif (filled($row['subcategory_slug'] ?? null)) {
            $subcategory = $this->resolveSubcategory($row);

            if ($subcategory) {
                $product->subcategory_id = $subcategory->id;
            }
        }

        foreach (self::PRODUCT_COLUMNS as $column => $cast) {
            if ($this->absent($row, $column)) {
                continue;
            }

            $product->{self::PRODUCT_ATTRIBUTE[$column]} = $this->cast($row[$column], $cast);
        }

        $this->setTranslations($product, $row, $locales, [
            'product_name' => 'name',
            'product_description' => 'description',
        ]);

        $product->save();

        $variationSlug = (string) ($row['variation_slug'] ?? '');

        if ($variationSlug === '') {
            return $created;
        }

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('slug', $variationSlug)
            ->first();

        if (!$variation) {
            $variation = new ProductsVariation();
            $variation->slug = $variationSlug;
            $variation->is_active = $this->cast($row['variation_is_active'] ?? null, 'bool') ?? 0;
            $variation->price = 0;
        }

        $variation->product_id = $product->id;

        foreach (self::VARIATION_COLUMNS as $column => $cast) {
            if ($this->absent($row, $column)) {
                continue;
            }

            $variation->{self::VARIATION_ATTRIBUTE[$column]} = $this->cast($row[$column], $cast);
        }

        $this->setTranslations($variation, $row, $locales, [
            'variation_name' => 'name',
            'variation_description' => 'description',
            'variation_unit_label' => 'unit_label',
        ]);

        $variation->save();

        return $created;
    }

    private function setTranslations($model, array $row, array $locales, array $map): void
    {
        foreach ($map as $column => $attribute) {
            foreach ($locales as $locale) {
                $key = $column . '_' . $locale;

                if ($this->absent($row, $key)) {
                    continue;
                }

                $model->translateOrNew($locale)->{$attribute} = $row[$key];
            }
        }
    }

    private function resolveSubcategory(array $row): ?Subcategory
    {
        $query = Subcategory::withoutGlobalScope('cms_draft_flag')
            ->where('slug', $row['subcategory_slug']);

        // Subcategory slugs are only unique within a category, so when the file names
        // one, use it to disambiguate rather than taking whichever row comes first.
        if (filled($row['category_slug'] ?? null)) {
            $category = Category::withoutGlobalScope('cms_draft_flag')
                ->where('slug', $row['category_slug'])
                ->first();

            if (!$category) {
                return null;
            }

            $query->where('category_id', $category->id);
        }

        return $query->first();
    }

    /**
     * Is this cell asking to be left alone?
     *
     * A blank cell means "keep the current value", never "clear it" — the whole file is
     * a spreadsheet an admin may have only partly filled in, and an export/re-import
     * round trip has to be a no-op. Note fgetcsv returns '' for an empty field and never
     * null, so a null-only check would never fire on a real upload.
     */
    private function absent(array $row, string $key): bool
    {
        return !array_key_exists($key, $row) || $row[$key] === null || trim((string) $row[$key]) === '';
    }

    private function cast($value, string $type)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'decimal' => round((float) $value, 2),
            // "1"/"0", but also the "TRUE"/"yes" a spreadsheet may produce.
            'bool' => in_array(strtolower((string) $value), ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            default => $value,
        };
    }

    private function same($old, $new, string $type): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        return match ($type) {
            'float', 'decimal' => abs((float) $old - (float) $new) < 0.0001,
            'int', 'bool' => (int) $old === (int) $new,
            default => (string) $old === (string) $new,
        };
    }
}
