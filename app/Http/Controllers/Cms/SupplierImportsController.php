<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\SupplierCategory;
use Hellotreedigital\Cms\Controllers\CmsPageController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The vendor Supplier imports list, narrowed to one supplier.
 *
 * `source` is a text column, so the vendor filter popup cannot offer it and
 * SanitizeCmsListFilters (integer ids only) cannot carry it.
 *
 * The choice is kept in the session because the vendor's post-save redirect is built in its
 * constructor from a fixed list of params. Carrying `source` in the URL alone would drop the
 * admin back into the full list after every row they edit.
 */
class SupplierImportsController extends Controller
{
    private const SESSION_KEY = 'cms.supplier_imports.source';

    public function index(Request $request, CmsPageController $cms)
    {
        $sources = $this->sources();
        $activeSource = $this->resolveSource($request, $sources);

        if ($activeSource === null) {
            return $cms->index('supplier-categories')
                ->with(['sources' => $sources, 'activeSource' => null]);
        }

        // A global scope because the vendor query has no hook, and because Eloquent groups the
        // existing wheres before applying one. The vendor search is bare orWhere()s, so this
        // yields (a OR b OR c) AND source = x, where a plain where() would be escaped by them.
        // Global scopes are static and have no public remove, hence the flag.
        $active = true;
        SupplierCategory::addGlobalScope('cms_source_filter', function (Builder $query) use (&$active, $activeSource) {
            if ($active) {
                $query->where('supplier_categories.source', $activeSource);
            }
        });

        try {
            return $cms->index('supplier-categories')
                ->with(['sources' => $sources, 'activeSource' => $activeSource]);
        } finally {
            $active = false;
        }
    }

    /** @return Collection<string,int> row count per source, drafts included as the list shows them */
    private function sources(): Collection
    {
        return DB::table('supplier_categories')
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->orderBy('source')
            ->pluck('total', 'source');
    }

    private function resolveSource(Request $request, Collection $sources): ?string
    {
        $source = $request->has('source')
            ? $request->query('source')
            : $request->session()->get(self::SESSION_KEY);

        if (is_string($source) && $sources->has($source)) {
            $request->session()->put(self::SESSION_KEY, $source);

            return $source;
        }

        $request->session()->forget(self::SESSION_KEY);

        return null;
    }
}
