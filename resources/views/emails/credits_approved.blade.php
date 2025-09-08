<h2>{{ __('emails.credits_approved.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<br>
{{ __('emails.credits_approved.intro') }}
<br>
<h1>{{ $amount }}</h1>
<br>
{{ __('emails.credits_approved.body_2') }}
<br><br>
{{ __('emails.credits_approved.closing') }}
