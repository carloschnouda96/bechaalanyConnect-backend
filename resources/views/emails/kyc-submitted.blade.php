<h2>{{ __('emails.kyc_submitted.hello_admin') }}</h2>
<br>
{{ __('emails.kyc_submitted.body') }}
<br>
<h3>{{ __('emails.kyc_submitted.user_details') }}</h3>
<p>{{ __('emails.kyc_submitted.fields.username') }} {{ $user->username }}</p>
<p>{{ __('emails.kyc_submitted.fields.email') }} {{ $user->email }}</p>
<p>{{ __('emails.kyc_submitted.fields.phone') }} {{ $user->phone_number }}</p>
<br>
{{ __('emails.kyc_submitted.closing') }}
