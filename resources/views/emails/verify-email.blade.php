<h2>{{ __('emails.verify_email.greeting', ['name' => $user['username'] ?? ($username ?? 'User')]) }}</h2>
<br>
{{ __('emails.verify_email.intro') }}
<br>
<h1>{{ $account_verification_code }}</h1>


