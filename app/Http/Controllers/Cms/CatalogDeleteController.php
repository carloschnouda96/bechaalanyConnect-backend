<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Order;
use App\Product;
use App\ProductsVariation;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Guards the delete button on the Products and Products Variations pages.
 *
 * `orders.product_variation_id` is ON DELETE RESTRICT (2026_08_10_000008) and
 * `products_variations.product_id` is ON DELETE CASCADE, so deleting a product tries to
 * take its variations with it and hits the same wall one level down. The vendor's
 * destroy() has no try/catch, so an admin got a raw `SQLSTATE[23000] ... 1451` naming a
 * constraint — and if the blocking order had itself been "deleted" in the CMS, it was
 * soft-deleted and therefore invisible on every page, so the error named a row the
 * admin could not find anywhere.
 *
 * This refuses the delete *before* MySQL does, and says which orders are in the way and
 * what to do about them.
 */
class CatalogDeleteController extends Controller
{
    /** @var CmsPageController */
    protected $cmsPageController;

    public function __construct(CmsPageController $cmsPageController)
    {
        $this->cmsPageController = $cmsPageController;
    }

    public function destroyProduct(Request $request, $id)
    {
        return $this->guard($id, 'products');
    }

    public function destroyVariation(Request $request, $id)
    {
        return $this->guard($id, 'products-variations');
    }

    /**
     * `$id` may be a comma-separated list: the vendor's bulk-delete JS rewrites the form
     * action to {prefix}/{route}/1,2,3 (assets/js/main.js:310-322). Same split the
     * vendor's own destroy() does.
     */
    private function guard($id, string $route)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $id)));

        if (empty($ids)) {
            return $this->refuse($route, 'Nothing was selected.');
        }

        $variationIds = $route === 'products'
            ? ProductsVariation::withoutGlobalScope('cms_draft_flag')->whereIn('product_id', $ids)->pluck('id')->all()
            : $ids;

        $blocking = $this->blockingOrders($variationIds);

        if ($blocking['live'] > 0 || $blocking['trashed'] > 0) {
            return $this->refuse($route, $this->explain($blocking, $route, $ids));
        }

        try {
            return $this->cmsPageController->destroy($id, $route);
        } catch (QueryException $e) {
            Log::warning('CMS catalog delete blocked by a foreign key', [
                'route' => $route,
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return $this->refuse(
                $route,
                'This could not be deleted because another record still references it. '
                    . 'Untick "is active" to hide it from the storefront instead.'
            );
        }
    }

    /**
     * withTrashed() is the whole point: a soft-deleted order is invisible to every other
     * CMS query but still holds the foreign key, so counting only live orders would let
     * this pass the guard and then fail in MySQL exactly as before.
     *
     * @param  array<int>  $variationIds
     * @return array{live:int, trashed:int}
     */
    private function blockingOrders(array $variationIds): array
    {
        if (empty($variationIds)) {
            return ['live' => 0, 'trashed' => 0];
        }

        $base = fn () => Order::withTrashed()
            ->withoutGlobalScope('cms_draft_flag')
            ->whereIn('product_variation_id', $variationIds);

        return [
            'live' => (clone $base())->whereNull('deleted_at')->count(),
            'trashed' => (clone $base())->whereNotNull('deleted_at')->count(),
        ];
    }

    /**
     * @param  array{live:int, trashed:int}  $blocking
     * @param  array<int>  $ids
     */
    private function explain(array $blocking, string $route, array $ids): string
    {
        $noun = $route === 'products' ? 'product' : 'product variation';
        $subject = count($ids) > 1 ? 'These ' . $noun . 's' : 'This ' . $noun;

        $parts = [];

        if ($blocking['live'] > 0) {
            $parts[] = $subject . ' has ' . $blocking['live'] . ' order(s) against it. '
                . 'Order history has to be kept, so it cannot be deleted — untick "is active" '
                . 'to hide it from the storefront instead.';
        }

        if ($blocking['trashed'] > 0) {
            $parts[] = ($blocking['live'] > 0 ? 'There are also ' : $subject . ' has ')
                . $blocking['trashed'] . ' deleted order(s) still pointing at it. '
                . 'Deleted orders keep their row so the money stays on record — open '
                . '"Deleted records" and delete them permanently first.';
        }

        if ($supplier = $this->supplierOf($route, $ids)) {
            $parts[] = 'Note: this is imported from ' . $supplier . '. The next catalog sync '
                . 'recreates it unless "import excluded" is ticked on the product.';
        }

        return implode(' ', $parts);
    }

    /**
     * The supplier that owns these rows, when they all come from one. Deleting a synced
     * product does not keep it gone, so saying so here saves the next round trip.
     *
     * @param  array<int>  $ids
     */
    private function supplierOf(string $route, array $ids): ?string
    {
        $query = Product::withoutGlobalScope('cms_draft_flag')->whereNotNull('external_source');

        if ($route === 'products') {
            $query->whereIn('id', $ids);
        } else {
            $query->whereIn('id', ProductsVariation::withoutGlobalScope('cms_draft_flag')
                ->whereIn('id', $ids)
                ->pluck('product_id'));
        }

        $sources = $query->distinct()->pluck('external_source');

        return $sources->count() === 1 ? $sources->first() : null;
    }

    private function refuse(string $route, string $message)
    {
        // The vendor layout checks for `success || error` but only ever prints
        // Session::get('success') (layouts/dashboard.blade.php:80-84), so an `error`
        // flash renders an empty toast. Cms\SupplierHealthController does the same.
        return redirect(config('hellotree.cms_route_prefix') . '/' . $route)->with('success', $message);
    }
}
