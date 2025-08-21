<?php

namespace App\Http\Controllers;

use App\DashboardMenuItem;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $dashboard_menu_items = DashboardMenuItem::orderBy('ht_pos')->get();

        return response()->json([
            'dashboard_menu_items' => $dashboard_menu_items
        ]);
    }
}
