<?php

namespace App\Http\Controllers;

use App\Category;
use App\Subcategory;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($locale, $slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $subcategories = Subcategory::where('is_active', 1)->whereHas('category', function ($query) use ($slug) {
            $query->where('slug', $slug);
        })
        ->orderBy('ht_pos')
        ->get();

        return response()->json([
            'subcategories' => $subcategories,
            'category' => $category->title
        ]);
    }
}
