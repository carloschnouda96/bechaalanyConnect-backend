<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Hard ceiling on rows returned, regardless of what the caller asks for. */
    private const MAX_RESULTS = 50;

    /**
     * Product search.
     *
     * Previously: Product::whereTranslationLike('name', '%'.$request->name.'%')->get()
     * with no validation, no locale scoping, no is_active filter and no limit.
     *
     * That had four problems:
     *   - an empty or one-character term matched the entire catalogue and returned
     *     every product, with Product::$with dragging subcategory.category along
     *   - inactive and import-excluded products showed up in results and linked to
     *     pages that cannot be bought
     *   - matches came from every locale at once, so an Arabic search returned rows
     *     matched on their English name
     *   - `%` and `_` in the term are LIKE wildcards; unescaped, a term of "%" was a
     *     guaranteed full-table scan
     */
    public function index($locale, Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_RESULTS],
        ]);

        $limit = min((int) ($validated['limit'] ?? 20), self::MAX_RESULTS);

        // Escape LIKE metacharacters so they are matched literally.
        $term = addcslashes($validated['name'], '%_\\');

        $products = Product::where('is_active', 1)
            ->whereHas('translations', function ($query) use ($term) {
                $query->where('locale', app()->getLocale())
                    ->where('name', 'like', '%' . $term . '%');
            })
            ->orderBy('ht_pos')
            ->limit($limit)
            ->get();

        return response()->json($products);
    }
}
