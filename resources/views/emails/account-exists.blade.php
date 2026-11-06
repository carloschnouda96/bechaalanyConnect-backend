{{-- No username: any anonymous caller can trigger this mail, so it carries no account text. --}}
<h2>{{ __('emails.account_exists.greeting', ['name' => __('emails.verify_email.customer')]) }}</h2>
<b>{{ __('emails.account_exists.body') }}</b><br>
<br>
{{ __('emails.account_exists.closing') }}
