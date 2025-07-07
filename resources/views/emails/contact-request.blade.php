<html>

<body>
    <h2><b>Hello Admin: </b></h2>
    <h4><b>You Have Received A New Email : </b></h4>

    <br><br>

    <p><b>Full Name: </b>{{ $contact_form->name }}</p>
    <p><b>Email: </b>{{ $contact_form->email }}</p>
    <p><b>Phone Number: </b>{{ $contact_form->phone }}</p>
    <p><b>Subject: </b>{{ $contact_form->subject }}</p>
    <p><b>Message: </b>{{ $contact_form->message }}</p>

    <br><br>
</body>

</html>
