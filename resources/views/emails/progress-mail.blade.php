<h1>Hello Admin,</h1>
<p>You have received a new email.</p>
<p>Kindly find the details in below:</p>
<p><b>Full Name:</b> {{ $request['first_name'] }} {{ $request['last_name'] }}</p>
<p><b>Email:</b> {{ $request['email'] }}</p>
<p><b>Phone:</b> {{ $request['phone_number'] }}</p>
<p><b>Current Weight:</b> {{ $request['current_weight'] }}</p>
<p><b>Message:</b> {{ $request['message'] }}</p>
