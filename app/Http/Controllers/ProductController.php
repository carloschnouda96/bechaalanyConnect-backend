<?php

namespace App\Http\Controllers;

use App\Category;
use App\Product;
use App\ProductsVariation;
use App\Subcategory;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($locale, $category_slug, $subcategory_slug)
    {
        $products = Product::where('is_active', 1)->whereHas('subcategory', function ($query) use ($subcategory_slug, $category_slug) {
            $query->whereHas('category', function ($q2) use ($category_slug) {
                $q2->where('slug', $category_slug);
            });
            $query->where('slug', $subcategory_slug);
        })->orderBy('ht_pos')->get();

        $subcategory = Subcategory::where('slug', $subcategory_slug)->first();
        $category = Category::where('slug', $category_slug)->first();
        return response()->json([
            'products' => $products,
            'subcategory' => $subcategory->title,
            'category' => $category->title
        ]);
    }

    public function SingleProduct($locale, $category_slug, $subcategory_slug, $slug)
    {
        $product_variations = ProductsVariation::whereHas('product', function ($query) use ($slug) {
            $query->where('slug', $slug);
        })
            ->orderBy('ht_pos')
            ->where('is_active', 1)
            ->get();
        $product = Product::where('slug', $slug)
            ->with('related_products')
            ->with('product_type')
            ->first();
        $subcategory = Subcategory::where('slug', $subcategory_slug)->first();
        $category = Category::where('slug', $category_slug)->first();
        return response()->json([
            'product_variations' => $product_variations,
            'product' => $product,
            'subcategory' => $subcategory->title,
            'category' => $category->title
        ]);
    }
}
