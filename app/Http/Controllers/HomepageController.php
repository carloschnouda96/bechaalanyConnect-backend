<?php

namespace App\Http\Controllers;

use App\BannerSwiper;
use App\HomepageSetting;
use App\Product;
use App\SeoPage;

class HomepageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $bannerSwiper = BannerSwiper::orderBy('ht_pos')->get();
        $homepageSettings = HomepageSetting::first();
        // Chosen and ordered in the CMS (Homepage Settings → Homepage categories).
        $categories = $homepageSettings
            ? $homepageSettings->homepage_categories()->where('categories.is_active', 1)->get()->makeHidden('pivot')
            : collect();
        $latest_products = Product::orderBy('created_at', 'desc')->listable()->take(4)->get();
        $seo = SeoPage::where('slug', 'home')->first();

        return response()->json([
            'bannerSwiper' => $bannerSwiper,
            'homepageSettings' => $homepageSettings,
            'categories' => $categories,
            'latest_products' => $latest_products,
            'seo' => $seo
        ]);
    }
}
