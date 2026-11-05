<h2>{{ __('emails.password_changed.greeting', ['name' => $user['username'] ? $user['username'] : 'User']) }}</h2>
<b>{{ __('emails.password_changed.body') }}</b><br>
<br>
@if (!empty($apiAccessRevoked))
{{ __('emails.password_changed.api_access_revoked') }}<br>
<br>
@endif
{{ __('emails.password_changed.closing') }}
