<?php

namespace App\Services\Cms;

use App\Product;
use Hellotreedigital\Cms\Models\Language;

/**
 * The one definition of the catalog CSV's shape.
 *
 * Export and import both read it, which is what makes "download, edit in Excel, upload
 * again" a closed loop rather than two formats that drift apart. Adding a column here
 * adds it to both sides at once.
 *
 * One row per VARIATION, with the parent product's columns repeated — because that is
 * what an admin is actually editing (a price sits on a variation), and it keeps the file
 * flat enough for a spreadsheet.
 */
class CatalogCsv
{
    /** Columns that exist once, regardless of how many languages are configured. */
    public const FIXED_COLUMNS = [
        'product_slug',
        'category_slug',
        'subcategory_slug',
        'product_type_id',
        'product_is_active',
        'variation_slug',
        'cost_price',
        'price',
        'profit_percentage',
        'unit_amount',
        'variation_is_active',
        // Informational only. Import never writes it — the supplier connectors own it,
        // and a row whose product carries one is refused. It is in the file so an admin
        // can see at a glance which rows they are not allowed to edit.
        'external_source',
    ];

    /** Per-language columns, suffixed with the language slug. */
    public const TRANSLATED_COLUMNS = [
        'product_name',
        'product_description',
        'variation_name',
        'variation_description',
        'variation_unit_label',
    ];

    /**
     * Configured languages, from the CMS `languages` table.
     *
     * NOT config('translatable.locales') — that is still the astrotomic package default
     * (en/fr/es) in this install, so trusting it would write French and Spanish
     * translations and never Arabic. Same resolution SupplierCatalogSync uses.
     */
    public static function locales(): array
    {
        try {
            $locales = Language::pluck('slug')->filter()->values()->all();
        } catch (\Throwable $e) {
            $locales = [];
        }

        return $locales ?: ['en', 'ar'];
    }

    /** The full ordered header. */
    public static function header(): array
    {
        $header = self::FIXED_COLUMNS;

        foreach (self::TRANSLATED_COLUMNS as $column) {
            foreach (self::locales() as $locale) {
                $header[] = $column . '_' . $locale;
            }
        }

        return $header;
    }

    /** Translated column name => model attribute, per side. */
    public static function translatedAttribute(string $column): array
    {
        return [
            'product_name' => ['product', 'name'],
            'product_description' => ['product', 'description'],
            'variation_name' => ['variation', 'name'],
            'variation_description' => ['variation', 'description'],
            'variation_unit_label' => ['variation', 'unit_label'],
        ][$column];
    }

    /**
     * Every product/variation pair as CSV rows.
     *
     * Drafts and inactive rows are included deliberately: an export an admin cannot
     * round-trip is worse than no export, and omitting a row would read as "delete me"
     * when they upload it again.
     */
    public static function rows(): \Generator
    {
        $locales = self::locales();

        $products = Product::withoutGlobalScope('cms_draft_flag')
            ->with(['translations', 'subcategory.category'])
            ->orderBy('id');

        foreach ($products->cursor() as $product) {
            $variations = $product->variations()
                ->withoutGlobalScope('cms_draft_flag')
                ->with('translations')
                ->orderBy('id')
                ->get();

            // A product with no variations still gets a line, so it survives a
            // round-trip instead of silently vanishing from the file.
            if ($variations->isEmpty()) {
                yield self::row($product, null, $locales);
                continue;
            }

            foreach ($variations as $variation) {
                yield self::row($product, $variation, $locales);
            }
        }
    }

    private static function row($product, $variation, array $locales): array
    {
        $row = [
            'product_slug' => self::neutralise($product->slug),
            'category_slug' => self::neutralise(optional(optional($product->subcategory)->category)->slug),
            'subcategory_slug' => self::neutralise(optional($product->subcategory)->slug),
            'product_type_id' => $product->product_type_id,
            'product_is_active' => (int) $product->is_active,
            'variation_slug' => $variation ? self::neutralise($variation->slug) : null,
            'cost_price' => $variation ? $variation->cost_price : null,
            'price' => $variation ? $variation->price : null,
            'profit_percentage' => $variation ? $variation->profit_percentage : null,
            'unit_amount' => $variation ? $variation->unit_amount : null,
            'variation_is_active' => $variation ? (int) $variation->is_active : null,
            'external_source' => $product->external_source,
        ];

        foreach (self::TRANSLATED_COLUMNS as $column) {
            [$side, $attribute] = self::translatedAttribute($column);
            $model = $side === 'product' ? $product : $variation;

            foreach ($locales as $locale) {
                $row[$column . '_' . $locale] = $model
                    ? self::neutralise(optional($model->translate($locale))->{$attribute})
                    : null;
            }
        }

        return $row;
    }

    /**
     * Stops a spreadsheet from running supplier-written text as a formula when an admin
     * opens the export. restore() undoes it on import, so a round trip changes nothing.
     */
    /**
     * One CSV line with every cell quoted. fputcsv leaves `a;=1+1;b` bare, and a spreadsheet
     * set to semicolon-separated files would split it and run the formula in the middle.
     */
    public static function line(array $cells): string
    {
        return implode(',', array_map(
            fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
            $cells
        ));
    }

    public static function neutralise($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        // Inside the text too: a spreadsheet set to another separator splits the cell there,
        // so a formula after it would start its own cell.
        $value = preg_replace('/([;,\t|\r\n"]\s*)([=+\-@])/', "$1'$2", $value);

        return preg_match('/^\s*[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public static function restore($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = preg_match('/^\'\s*[=+\-@\t\r]/', $value) ? substr($value, 1) : $value;

        return preg_replace('/([;,\t|\r\n"]\s*)\'([=+\-@])/', '$1$2', $value);
    }

    /**
     * Parse an uploaded file into header-keyed rows.
     *
     * Native fgetcsv rather than a CSV package: it handles quoting and embedded
     * newlines correctly and adds no dependency to composer.json.
     *
     * @return array{header: array, rows: array, errors: array}
     */
    public static function parse(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ['header' => [], 'rows' => [], 'errors' => ['The file could not be opened.']];
        }

        // Skipped before parsing: a BOM in front of a quoted first cell would make the quotes
        // literal, so that cell (and the whole header) would no longer match.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ',', '"', '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            return ['header' => [], 'rows' => [], 'errors' => ['The file is empty.']];
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);

        $errors = [];
        $expected = self::header();
        $missing = array_diff(['product_slug', 'variation_slug'], $header);

        if ($missing) {
            $errors[] = 'Missing required column(s): ' . implode(', ', $missing) . '.';
        }

        $unknown = array_diff($header, $expected);

        if ($unknown) {
            $errors[] = 'Unrecognised column(s) ignored: ' . implode(', ', $unknown) . '.';
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            // fgetcsv yields [null] for a blank line.
            if ($values === [null] || $values === false) {
                continue;
            }

            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($header as $i => $name) {
                $cell = array_key_exists($i, $values) ? trim((string) $values[$i]) : null;
                // Only text columns were neutralised on export; a slug or number is left alone.
                $translated = preg_replace('/_[a-z]{2}$/', '', (string) $name);
                $row[$name] = in_array($translated, self::TRANSLATED_COLUMNS, true) ? self::restore($cell) : $cell;
            }

            $row['__line'] = $line;
            $rows[] = $row;
        }

        fclose($handle);

        return ['header' => $header, 'rows' => $rows, 'errors' => $errors];
    }
}
