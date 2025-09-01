<?php

namespace App\Http\Controllers;

use App\DashboardMenuItem;
use App\DashboardPageSetting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $dashboard_menu_items = DashboardMenuItem::orderBy('ht_pos')->get();
        $dashboard_page_settings = DashboardPageSetting::firstOrFail();


        return response()->json([
            'dashboard_menu_items' => $dashboard_menu_items,
            'dashboard_page_settings' => $dashboard_page_settings

        ]);
    }
}
