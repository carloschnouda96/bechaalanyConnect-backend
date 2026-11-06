<h2>{{ __('emails.api_key_created.greeting', ['name' => $user['username'] ?: 'User']) }}</h2>
<b>{{ __('emails.api_key_created.body', ['key_name' => $keyName]) }}</b><br>
<br>
{{ __('emails.api_key_created.closing') }}
