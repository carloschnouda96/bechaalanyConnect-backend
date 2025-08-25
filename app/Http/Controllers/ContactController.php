<?php

namespace App\Http\Controllers;

use App\ContactDetail;
use App\ContactFormRequest;
use App\ContactFormSubject;
use App\ContactPageSetting;
use App\FixedSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

    public function submit(Request $request)
    {
        $requestedLocale = $request->get('lang') ?? $request->getPreferredLanguage(['en', 'ar']);
        if (in_array($requestedLocale, ['en', 'ar'])) {
            app()->setLocale($requestedLocale);
        }
        $admin_email = FixedSetting::first()->admin_email;

        $request->validate([
            'name' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'subject' => 'required',
            'message' => 'required'
        ]);

        $contact_form = new ContactFormRequest();
        $contact_form->name = $request->name;
        $contact_form->email = $request->email;
        $contact_form->phone = $request->phone;
        $contact_form->subject = $request->subject;
        $contact_form->message = $request->message;
        $contact_form->save();

    Mail::send('emails.contact-request', compact('contact_form', 'requestedLocale'), function ($message) use ($contact_form, $admin_email) {
            $message->to($admin_email)->subject($contact_form->subject);
        });

        return response()->json([
            'message' => 'Contact form submitted successfully'
        ]);
    }
}
