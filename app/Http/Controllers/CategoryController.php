<?php

namespace App\Http\Controllers;

use App\Category;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::where('is_active', 1)->get();
        return response()->json([
            'categories' => $categories
        ]);
    }
}
