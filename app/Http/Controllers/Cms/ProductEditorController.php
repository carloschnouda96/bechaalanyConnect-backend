<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Product;
use App\ProductsVariation;
use App\Services\Cms\CatalogCsv;
use App\Services\Cms\VariationPricing;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The product page as the one place a product is set up: its own fields (the vendor
 * form, reordered by pages/products/form.blade.php), plus a Variations card listing
 * every variation with its Public and per-user-type prices, and a quick-add row.
 *
 * Before this, adding one hand-made product took three pages that did not link to one
 * another: Products, then Products Variations (once per amount, picking the parent from
 * a list of every product), then the Price matrix for tier prices.
 *
 * Every price written here goes through VariationPricing — the same service as the
 * Price matrix — so the two screens cannot disagree.
 */
class ProductEditorController extends Controller
{
    public function __construct(
        private CmsPageController $cms,
        private VariationPricing $pricing
    ) {
    }

    /** The vendor edit page, plus what the Variations card needs. */
    public function edit($id)
    {
        $view = $this->cms->edit($id, 'products');

        $product = Product::withoutGlobalScope('cms_draft_flag')->findOrFail($id);
        $locale = app()->getLocale();

        $variations = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['translations', 'priceVariations'])
            ->where('product_id', $product->id)
            ->orderBy('ht_pos')
            ->orderBy('id')
            ->get();

        $rows = $variations->map(fn (ProductsVariation $variation) => [
            'id' => $variation->id,
            'name' => optional($variation->translate($locale))->name ?: $variation->slug,
            'is_active' => (bool) $variation->is_active,
            'draft' => (int) $variation->cms_draft_flag === 1,
            'supplier_status' => $variation->supplier_status,
            'cost' => VariationPricing::costOf($variation),
            'cells' => $this->pricing->cells($variation),
        ]);

        return $view->with('editor', [
            'product' => $product,
            'rows' => $rows,
            'user_types' => $this->pricing->userTypes(),
            'default_profit' => $this->pricing->defaultProfit(),
            'locales' => CatalogCsv::locales(),
            // What the storefront listings check (Product::scopeListable) — the reason a
            // product can be switched on and still not appear anywhere.
            'for_sale' => Product::withoutGlobalScope('cms_draft_flag')->listable()->whereKey($product->id)->exists(),
        ]);
    }

    /**
     * The vendor create, but landing on the new product's edit page instead of the
     * product list — the next thing to do with a new product is give it variations.
     */
    public function store(Request $request)
    {
        $before = (int) Product::withoutGlobalScope('cms_draft_flag')->max('id');

        $redirect = $this->cms->store($request, 'products');

        if (!is_string($redirect)) {
            return $redirect;
        }

        $created = Product::withoutGlobalScope('cms_draft_flag')
            ->where('id', '>', $before)
            ->orderBy('id')
            ->first();

        if (!$created) {
            return $redirect;
        }

        $request->session()->flash('success', 'Product created. Now add its variations (amounts) below.');

        return url(config('hellotree.cms_route_prefix') . '/products/' . $created->id . '/edit#variations');
    }

    /**
     * Quick-add one variation from the product page: its name, its cost and a price.
     * Description and image are on the full variation form.
     *
     * Cost is required, as on the variation form (the vendor schema marks cost_price
     * NOT nullable): it is what profit reporting and a profit % are computed from.
     *
     * Supplier products are refused: their variations are created and matched by the
     * catalog sync, and a hand-made one would be sold but never fulfilled.
     */
    public function addVariation(Request $request, $id)
    {
        $product = Product::withoutGlobalScope('cms_draft_flag')->findOrFail($id);

        if (filled($product->external_source)) {
            return $this->backToVariations($product)->withErrors([
                'quick_add' => "This product is imported from {$product->external_source}; its variations come from the supplier sync.",
            ]);
        }

        $locales = CatalogCsv::locales();
        $primary = $locales[0];

        $data = $request->validate([
            "name.{$primary}" => ['required', 'string', 'max:191'],
            'name.*' => ['nullable', 'string', 'max:191'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'pricing_mode' => ['required', Rule::in(['fixed', 'percent'])],
            'pricing_value' => ['required', 'numeric', 'min:-100', 'max:1000000'],
        ], [
            "name.{$primary}.required" => 'Give the variation a name (e.g. "100 UC").',
            'pricing_value.required' => 'Enter a price or a profit %.',
            'cost_price.required' => 'Enter what one unit costs you.',
        ]);

        $cost = (float) $data['cost_price'];
        $value = (float) $data['pricing_value'];

        if ($data['pricing_mode'] === 'fixed' && $value < 0) {
            return $this->backToVariations($product)->withInput()->withErrors([
                'quick_add' => 'A fixed price must be 0 or more.',
            ]);
        }

        $name = $data['name'][$primary];

        DB::transaction(function () use ($product, $data, $locales, $name, $cost, $value) {
            $variation = new ProductsVariation();
            $variation->product_id = $product->id;
            $variation->slug = $this->uniqueSlug($product->slug . '-' . $name);
            $variation->is_active = 1;
            $variation->cms_draft_flag = 0;
            $variation->ht_pos = ProductsVariation::nextHtPos();
            $variation->cost_price = $cost;

            // Same end state VariationPricing::applyPublic() leaves: a fixed price is
            // locked (manual_price); a % is stored and ProductsVariationObserver::creating
            // derives the price from cost.
            if ($data['pricing_mode'] === 'fixed') {
                $variation->price = $value;
                $variation->manual_price = true;
            } else {
                $variation->profit_percentage = $value;
            }

            // A missing translation would show an empty name on that storefront; the
            // primary-language name is a better fallback than nothing.
            foreach ($locales as $locale) {
                $variation->translateOrNew($locale)->name = filled($data['name'][$locale] ?? null)
                    ? $data['name'][$locale]
                    : $name;
            }

            $variation->save();
        });

        return $this->backToVariations($product)->with('success', "Variation \"{$name}\" added.");
    }

    /**
     * Save edited price cells from the product page. Only this product's variations are
     * touched, whatever ids the form carries.
     *
     * PUT under /products, so AdminMiddleware checks the Products `edit` right — anyone
     * who may edit a product may price it here.
     */
    public function prices(Request $request, $id)
    {
        $product = Product::withoutGlobalScope('cms_draft_flag')->findOrFail($id);

        $request->validate(['changes' => ['required', 'string', 'max:200000']]);

        $changes = VariationPricing::changesFromJson($request->input('changes'));

        if ($changes->isEmpty()) {
            return $this->backToVariations($product)->withErrors(['changes' => 'Nothing to save — edit a price first.']);
        }

        if ($errors = $this->pricing->validate($changes)) {
            return $this->backToVariations($product)->withErrors($errors);
        }

        $result = $this->pricing->apply($changes, $product->id);

        return $this->backToVariations($product)
            ->with('success', "{$result['changed']} price(s) updated." . ($result['skipped'] ? ' ' . count($result['skipped']) . ' skipped.' : ''))
            ->with('price_matrix_skipped', $result['skipped']);
    }

    private function backToVariations(Product $product)
    {
        return redirect(url(config('hellotree.cms_route_prefix') . '/products/' . $product->id . '/edit') . '#variations');
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'variation';
        $base = Str::limit($base, 180, '');
        $slug = $base;
        $n = 2;

        while (ProductsVariation::withoutGlobalScope('cms_draft_flag')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
