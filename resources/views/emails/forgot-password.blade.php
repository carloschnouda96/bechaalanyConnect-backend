<h2>{{ __('emails.forgot_password.greeting') }}</h2>
<b>{{ __('emails.forgot_password.body_1') }}</b><br>
{{ __('emails.forgot_password.body_2') }}<br>
<br>
<a style=" background-color: #e73828;
  border: none;
  color: white;
  padding: 10px 16px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
  font-size: 16px;"
  href="{{ $reset_password_url }}" target="_blank">{{ __('emails.forgot_password.button') }}</a>
