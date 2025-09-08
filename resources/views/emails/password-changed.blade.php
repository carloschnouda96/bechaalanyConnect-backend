<h2>{{ __('emails.password_changed.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<b>{{ __('emails.password_changed.body') }}</b><br>
<br>
{{ __('emails.password_changed.closing') }}
