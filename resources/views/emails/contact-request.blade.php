<html>

    <body align={{ $requestedLocale == 'ar' ? 'right' : 'left' }}>
        <h2><b>{{ __('emails.contact_request.hello_admin') }} </b></h2>
        <h4><b>{{ __('emails.contact_request.received_new_email') }} </b></h4>

        <br><br>

        <p><b>{{ __('emails.contact_request.fields.full_name') }} </b>{{ $contact_form->name }}</p>
        @if ($requestedLocale == 'ar')
            <p><b>{{ $contact_form->email }} {{ __('emails.contact_request.fields.email') }} </b></p>
        @else
            <p><b>{{ __('emails.contact_request.fields.email') }} </b>{{ $contact_form->email }}</p>
        @endif
        <p><b>{{ __('emails.contact_request.fields.phone') }} </b>{{ $contact_form->phone }}</p>
        <p><b>{{ __('emails.contact_request.fields.subject') }} </b>{{ $contact_form->subject }}</p>
        <p><b>{{ __('emails.contact_request.fields.message') }} </b>{{ $contact_form->message }}</p>

        <br><br>
    </body>

</html>
