<?php

namespace App\Http\Controllers;

use App\BannerSwiper;
use App\Category;
use App\HomepageSetting;
use App\Product;
use Illuminate\Http\Request;

class HomepageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $bannerSwiper = BannerSwiper::orderBy('ht_pos')->get();
        $homepageSettings = HomepageSetting::first();
        $categories = Category::where('is_active', 1)->get()->take(6);
        $latest_products = Product::orderBy('created_at', 'desc')->where('is_active', 1)->take(4)->get();

        return response()->json([
            'bannerSwiper' => $bannerSwiper,
            'homepageSettings' => $homepageSettings,
            'categories' => $categories,
            'latest_products' => $latest_products
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
