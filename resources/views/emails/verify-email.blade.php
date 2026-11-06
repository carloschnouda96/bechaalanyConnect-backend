{{-- No username: this goes to an address nobody has proven yet, so it must carry no text the requester chose. --}}
<h2>{{ __('emails.verify_email.greeting', ['name' => __('emails.verify_email.customer')]) }}</h2>
<br>
{{ __('emails.verify_email.intro') }}
<br>
<h1>{{ $account_verification_code }}</h1>

@isset($confirm_email_url)
<br>
{{ __('emails.verify_email.link_instruction') }}<br>
<br>
<a style=" background-color: #e73828;
  border: none;
  color: white;
  padding: 10px 16px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
  font-size: 16px;"
  href="{{ $confirm_email_url }}" target="_blank">{{ __('emails.verify_email.button') }}</a>
@endisset

