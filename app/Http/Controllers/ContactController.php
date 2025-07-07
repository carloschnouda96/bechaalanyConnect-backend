<?php

namespace App\Http\Controllers;

use App\ContactDetail;
use App\ContactFormSubject;
use App\ContactPageSetting;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index()
    {
        $contact_page_setting = ContactPageSetting::first();
        $contact_details = ContactDetail::where('is_main', 1)->first();
        $contact_form_subjects = ContactFormSubject::orderBy('ht_pos')->get();
        return response()->json([
            'contact_page_setting' => $contact_page_setting,
            'contact_details' => $contact_details,
            'contact_form_subjects' => $contact_form_subjects
        ]);
    }
}
