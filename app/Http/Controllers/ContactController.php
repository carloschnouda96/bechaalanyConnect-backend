<?php

namespace App\Http\Controllers;

use App\ContactDetail;
use App\ContactFormRequest;
use App\ContactFormSubject;
use App\ContactPageSetting;
use App\FixedSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\SeoPage;

class ContactController extends Controller
{
    public function index()
    {
        $contact_page_setting = ContactPageSetting::first();
        $contact_details = ContactDetail::where('is_main', 1)->first();
        $contact_form_subjects = ContactFormSubject::orderBy('ht_pos')->get();
        $seo = SeoPage::where('slug', 'contact')->first();

        return response()->json([
            'contact_page_setting' => $contact_page_setting,
            'contact_details' => $contact_details,
            'contact_form_subjects' => $contact_form_subjects,
            'seo' => $seo
        ]);
    }

    public function submit(Request $request)
    {
        // Null-safe: was FixedSetting::first()->admin_email, which fataled when the
        // settings row was missing or draft-flagged.
        $admin_email = FixedSetting::adminEmail();

        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:32'],
            // Becomes the mail subject.
            'subject' => ['required', 'string', 'max:191', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contact_form = new ContactFormRequest();
        $contact_form->name = $request->name;
        $contact_form->email = $request->email;
        $contact_form->phone = $request->phone;
        $contact_form->subject = $request->subject;
        $contact_form->message = $request->message;
        $contact_form->save();

        // Same undefined-variable bug as forgot-password: compact() warned, Laravel
        // turned that into a 500 after the row was already saved, and no mail went out.
        $requestedLocale = app()->getLocale();

        // The request is saved; a mail problem must not tell the visitor it failed.
        try {
            if (filled($admin_email)) {
                Mail::send('emails.contact-request', compact('contact_form', 'requestedLocale'), function ($message) use ($contact_form, $admin_email) {
                    $message->to($admin_email)->subject($contact_form->subject);
                });
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Contact request mail failed', ['exception' => $e]);
        }

        return response()->json([
            'message' => __('api.contact.submitted')
        ]);
    }
}
