<?php

return [
    'subjects' => [
        'verify_email' => 'Email Confirmation',
        'reset_password' => 'Reset Password',
        'new_order' => 'New Order Request',
    ],
    'verify_email' => [
        'greeting' => 'Dear :name,',
        'intro' => 'This is your verification code:',
    ],
    'forgot_password' => [
        'greeting' => 'Dear User,',
        'body_1' => 'Your password has been reset.',
        'body_2' => 'Kindly reset your password by clicking on the following button :',
        'button' => 'Reset Password',
    ],
    'order_request' => [
        'hello_admin' => 'Hello Admin:',
        'received_new_order' => 'You Have Received A New Order :',
        'user_details' => 'User Details:',
        'order_details' => 'Order Details:',
        'fields' => [
            'full_name' => 'Full Name:',
            'email' => 'Email:',
            'phone' => 'Phone Number:',
            'order_id' => 'Order ID:',
            'product_name' => 'Product Name:',
            'quantity' => 'Quantity:',
            'price' => 'Price:',
        ],
    ],
    'credits_approved' => [
        'greeting' => 'Dear :name,',
        'intro' => 'Your credits request has been approved for the amount:',
        'body_2' => 'Thank you for using our service!',
        'closing' => 'Best regards, The Team',
    ],
    'contact_request' => [
        'hello_admin' => 'Hello Admin:',
        'received_new_email' => 'You Have Received A New Email :',
        'fields' => [
            'full_name' => 'Full Name:',
            'email' => 'Email:',
            'phone' => 'Phone Number:',
            'subject' => 'Subject:',
            'message' => 'Message:',
        ],
    ],
];
