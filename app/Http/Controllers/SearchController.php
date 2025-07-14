<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index($locale, Request $request)
    {
        $search_term = $request->name;
        $products = Product::whereTranslationLike('name', '%' . $search_term . '%')->with('subcategory.category')->get();
        return response()->json($products);
    }
}
