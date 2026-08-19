<h2>{{ __('emails.kyc_rejected.greeting', ['name' => $user->username ?? 'User']) }}</h2>
<br>
{{ __('emails.kyc_rejected.body') }}
<br>

{{-- The specific reason, when the admin gave one. Without this the email could only
     say "rejected, please resubmit clear photos", so an applicant had no idea what to
     change and typically resubmitted the same documents. --}}
@if (filled($user->rejection_reason ?? null))
    <br>
    <strong>{{ __('emails.kyc_rejected.reason_label') }}</strong>
    <br>
    {{ $user->rejection_reason }}
    <br>
@endif

<br>
{{ __('emails.kyc_rejected.closing') }}
