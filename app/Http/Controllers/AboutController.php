<?php

namespace App\Http\Controllers;

use App\AboutPageSetting;
use App\ContactDetail;
use Illuminate\Http\Request;

class AboutController extends Controller
{
    public function index()
    {
        $about_page_setting = AboutPageSetting::first();
        $contact_details = ContactDetail::orderBy('ht_pos')->get();

        return response()->json([
            'about_page_setting' => $about_page_setting,
            'contact_details' => $contact_details
        ]);
    }
}
