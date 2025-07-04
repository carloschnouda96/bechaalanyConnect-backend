<?php

namespace App\Http\Controllers;

use App\Category;
use App\Product;
use App\Subcategory;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($locale, $slug, $subcategory_slug)
    {
        $products = Product::where('is_active', 1)->whereHas('subcategory', function ($query) use ($subcategory_slug, $slug) {
            $query->whereHas('category', function ($q2) use ($slug) {
                $q2->where('slug', $slug);
            });
            $query->where('slug', $subcategory_slug);
        })->get();

        $subcategory = Subcategory::where('slug', $subcategory_slug)->first();
        $category = Category::where('slug', $slug)->first();
        return response()->json([
            'products' => $products,
            'subcategory' => $subcategory->title,
            'category' => $category->title
        ]);
    }
}
