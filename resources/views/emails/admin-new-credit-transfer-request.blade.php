<h1>
    New Credit Transfer Request
</h1>
<br>
A new credit transfer request has been submitted by user:
<br>
<b>{{ $user->username }} (ID: {{ $user->id }}).</b>
<br>
<br>
Details of the request:
<br>
- Amount: <b>{{ $transferRequest->amount }}</b>
<br>
- Requested At: <b>{{ $transferRequest->created_at }}</b>
<br>
<br>
Please review and process the request as necessary.
<br>
Thank you.
