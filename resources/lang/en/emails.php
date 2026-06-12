<?php

return [
    'subjects' => [
        'verify_email' => 'Email Confirmation',
        'reset_password' => 'Reset Password',
        'new_order' => 'New Order Request',
        'kyc_submitted' => 'New Account Verification Request',
        'kyc_approved' => 'Your Account Has Been Verified',
        'kyc_rejected' => 'Account Verification Rejected',
    ],
    'kyc_submitted' => [
        'hello_admin' => 'Hello Admin:',
        'body' => 'A user has submitted identity verification documents and is waiting for approval:',
        'user_details' => 'User Details:',
        'fields' => [
            'username' => 'Username:',
            'email' => 'Email:',
            'phone' => 'Phone Number:',
        ],
        'closing' => 'Please review the documents from the admin panel (Users page).',
    ],
    'kyc_approved' => [
        'greeting' => 'Dear :name,',
        'body' => 'Your account has been verified successfully. You can now use all platform features, place orders and add credits.',
        'closing' => 'Thank you for joining us!',
    ],
    'kyc_rejected' => [
        'greeting' => 'Dear :name,',
        'body' => 'Unfortunately, your identity verification documents were rejected. Please sign in and resubmit clear photos of your ID (front and back) and a new selfie.',
        'closing' => 'If you believe this is a mistake, please contact support.',
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
    'password_changed' => [
        'greeting' => 'Dear :name,',
        'body' => 'Your password has been changed successfully.',
        'closing' => 'If you did not make this change, please contact support.',
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
