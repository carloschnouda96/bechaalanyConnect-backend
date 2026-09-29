<?php

namespace App\Http\Controllers;

use App\Category;
use App\Product;
use App\Subcategory;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * A subcategory flagged `show_products_in_category` is not a storefront
     * level: it is left out of `subcategories` and its products are returned
     * under `products`, to be listed on the category page itself.
     */
    public function index($locale, $slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $subcategories = Subcategory::where('is_active', 1)
            ->where('category_id', $category->id)
            ->shownAsLevel()
            ->orderBy('ht_pos')
            ->get();

        $products = Product::sellable()
            ->whereHas('subcategory', function ($query) use ($category) {
                $query->where('is_active', 1)
                    ->where('category_id', $category->id)
                    ->where('show_products_in_category', 1);
            })
            ->orderBy('ht_pos')
            ->get();

        return response()->json([
            'subcategories' => $subcategories,
            'products' => $products,
            'category' => $category->title
        ]);
    }
}
