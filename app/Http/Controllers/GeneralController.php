<?php

namespace App\Http\Controllers;

use App\Country;
use App\FixedSetting;
use App\LoggingPagesSetting;
use App\MenuItem;
use App\SocialMediaLink;
use App\UserType;
use Illuminate\Http\Request;
use Hellotreedigital\Cms\Models\Language;


class GeneralController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $settings =  FixedSetting::firstOrFail();
        $menu_items = MenuItem::orderBy('ht_pos')->get();
        $social_links = SocialMediaLink::orderBy('ht_pos')->get();
        $locale = Language::get()->keyBy('slug');
        $user_types = UserType::orderBy('ht_pos')->get();
        $countries = Country::orderBy('ht_pos')->get();
        $logging_page_settings = LoggingPagesSetting::firstOrFail();

        return response()->json([
            'settings' => $settings,
            'menu_items' => $menu_items,
            'social_links' => $social_links,
            'locale' => $locale,
            'user_types' => $user_types,
            'countries' => $countries,
            'logging_page_settings' => $logging_page_settings,
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
