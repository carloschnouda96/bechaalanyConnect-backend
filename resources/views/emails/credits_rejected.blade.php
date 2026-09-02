<h2>{{ __('emails.credits_rejected.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<br>
{{ __('emails.credits_rejected.intro') }}
<br>
<h1>{{ $amount }}</h1>
@if (filled($reason ?? null))
    <br>
    <strong>{{ __('emails.credits_rejected.reason_label') }}</strong> {{ $reason }}
@endif
<br><br>
{{ __('emails.credits_rejected.closing') }}
