<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\ProductsVariation;
use App\Services\Cms\VariationPricing;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save path for the variation form (pages/products-variations/form.blade.php).
 *
 * The form used to show Price, Cost price and Profit % as three free-standing inputs
 * whose interaction was invisible: editing Price silently locked it (manual_price),
 * editing Profit % silently repriced and unlocked it, and a row could end up showing a
 * 3% profit next to a locked price that ignored it. The form now asks one question —
 * "profit % on cost" or "fixed price" — and posts it as `pricing_mode`.
 *
 * The vendor still does the save (fields, translations, image, draft flag); afterwards
 * the chosen mode is applied through VariationPricing::applyPublic(), the same call the
 * Price matrix makes. That call is idempotent, so it only acts when the vendor save left
 * the row in a different state than the admin asked for — e.g. switching back to %
 * without changing the number, which the observer alone never unlocked.
 *
 * A request without `pricing_mode` (another client, an older form) passes straight
 * through to the vendor exactly as before.
 */
class VariationFormController extends Controller
{
    public function __construct(
        private CmsPageController $cms,
        private VariationPricing $pricing
    ) {
    }

    public function store(Request $request)
    {
        $mode = $this->validatedMode($request, null);

        if ($mode === 'percent') {
            // A brand new row has no stored price to keep; give it the derived one up
            // front (ProductsVariationObserver::creating only does so when a profit % is
            // set, and blank here means "use the default").
            $request->merge(['price' => $this->derivedPrice($request)]);
        }

        $before = (int) ProductsVariation::withoutGlobalScope('cms_draft_flag')->max('id');

        $redirect = $this->cms->store($request, 'products-variations');

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->where('id', '>', $before)
            ->orderBy('id')
            ->first();

        if ($variation && $mode !== null) {
            $this->applyMode($variation, $mode, $request);
        }

        return $this->redirectFor($request, $variation, $redirect);
    }

    public function update(Request $request, $id)
    {
        $existing = ProductsVariation::withoutGlobalScope('cms_draft_flag')->findOrFail($id);
        $mode = $this->validatedMode($request, $existing);

        $redirect = $this->cms->update($request, $id, 'products-variations');

        $variation = ProductsVariation::withoutGlobalScope('cms_draft_flag')->find($id);

        if ($variation && $mode !== null) {
            $this->applyMode($variation, $mode, $request);
        }

        return $this->redirectFor($request, $variation, $redirect);
    }

    /** @return string|null 'percent' | 'fixed', or null when the form did not send one */
    private function validatedMode(Request $request, ?ProductsVariation $existing): ?string
    {
        if (!$request->has('pricing_mode')) {
            return null;
        }

        $request->validate([
            'pricing_mode' => ['required', Rule::in(['percent', 'fixed'])],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'profit_percentage' => ['nullable', 'numeric', 'between:-100,10000'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $mode = $request->input('pricing_mode');

        // The cost the save will leave behind: the posted one, or — for a supplier row,
        // whose cost input is read-only — the supplier's recorded cost.
        $cost = $request->filled('cost_price')
            ? (float) $request->input('cost_price')
            : ($existing ? VariationPricing::costOf($existing) : null);

        if ($mode === 'percent' && $cost === null) {
            throw ValidationException::withMessages([
                'cost_price' => 'A profit % needs a cost price. Enter the cost, or choose "Fixed price".',
            ]);
        }

        if ($mode === 'fixed' && !$request->filled('price')) {
            throw ValidationException::withMessages([
                'price' => 'Enter the fixed price customers pay.',
            ]);
        }

        return $mode;
    }

    private function applyMode(ProductsVariation $variation, string $mode, Request $request): void
    {
        if ($mode === 'fixed') {
            $this->pricing->applyPublic($variation, 'fixed', (float) $request->input('price'));

            return;
        }

        $percentage = $request->filled('profit_percentage') ? (float) $request->input('profit_percentage') : null;
        $this->pricing->applyPublic($variation, $percentage === null ? 'clear' : 'percent', $percentage);
    }

    private function derivedPrice(Request $request): ?float
    {
        if (!$request->filled('cost_price')) {
            return null;
        }

        $percentage = $request->filled('profit_percentage')
            ? (float) $request->input('profit_percentage')
            : $this->pricing->defaultProfit();

        return ProductsVariation::computeSellingPrice((float) $request->input('cost_price'), $percentage);
    }

    /**
     * Back to the parent product's page when the form was opened from it, otherwise
     * wherever the vendor was going (the variation list).
     */
    private function redirectFor(Request $request, ?ProductsVariation $variation, $redirect)
    {
        if (is_string($redirect) && $variation && $request->input('return_to') === 'product') {
            return url(config('hellotree.cms_route_prefix') . '/products/' . $variation->product_id . '/edit#variations');
        }

        return $redirect;
    }
}
