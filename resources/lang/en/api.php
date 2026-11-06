<?php

/*
|--------------------------------------------------------------------------
| API response messages
|--------------------------------------------------------------------------
|
| Every user-facing `message`/`error` string the JSON API returns. These were
| hardcoded English in the controllers, so an Arabic customer got English errors
| even on the /{locale} routes where App::setLocale() was already correct.
|
| Keep in sync, key for key, with resources/lang/ar/api.php.
|
*/

return [
    'unauthorized' => 'Unauthorized',

    'errors' => [
        'not_found' => 'The requested resource was not found.',
        'forbidden' => 'This action is unauthorized.',
        'rate_limited' => 'Too many attempts. Please wait a moment and try again.',
        'http_error' => 'Request failed.',
        'unexpected' => 'Something went wrong on our side. Please try again.',
    ],

    'orders' => [
        'not_found' => 'Order not found.',
        'kyc_required' => 'Your account must be verified before placing orders.',
        'unavailable' => 'This product is no longer available.',
        'phone_required' => 'A phone number is required for this product.',
        'user_id_required' => 'A user ID is required for this product.',
        'insufficient_credits' => 'Not enough credits to place this order.',
        'min_quantity' => 'The minimum for this product is :min.',
        'max_quantity' => 'The maximum for this product is :max.',
        'invalid_phone' => 'Enter a valid phone number (7-15 digits, optional leading +).',
    ],

    'credits' => [
        'too_many_pending' => 'You already have several top-ups waiting for review. Please wait until they are processed.',
        'kyc_required' => 'Your account must be verified before requesting credits.',
        'request_submitted' => 'Transfer credit request submitted successfully.',
        'receipt_not_found' => 'Receipt not found.',
    ],

    'kyc' => [
        'already_submitted' => 'Your verification documents have already been submitted.',
        'submitted' => 'Verification documents submitted successfully. Your account is pending approval.',
    ],

    'notifications' => [
        'not_found' => 'Notification not found.',
        'marked_read' => 'Notification marked as read.',
        'all_marked_read' => 'All notifications marked as read.',
        'deleted' => 'Notification deleted successfully',
        'delete_failed' => 'Failed to delete notification',
        'no_read_found' => 'No read notifications found',
        'all_read_deleted' => 'All read notifications deleted successfully',
        'delete_read_failed' => 'Failed to delete read notifications',
        'acknowledged' => 'Notification acknowledged',
    ],

    'session' => [
        'logged_out' => 'Logged out successfully.',
        'profile_updated' => 'Profile updated successfully.',
    ],

    'contact' => [
        'submitted' => 'Contact form submitted successfully',
    ],

    'auth' => [
        'registered' => 'Registration successful. Please verify your email.',
        'registered_no_email' => 'Your account was created, but the verification email could not be sent. Please use "resend code".',
        'password_required' => 'Choose a password to finish activating this account.',
        'username_taken' => 'That username was taken while your sign-up was waiting. Please sign up again with another one.',
        'too_many_codes' => 'Too many incorrect codes. Use the link in your verification email, or request a new code.',
        'google_email_conflict' => 'This Google account cannot be used here because its email address conflicts with an existing account.',
        'verification_failed' => 'Email verification failed. Check the code and try again.',
        'email_verified' => 'Email verified successfully.',
        'code_resent' => 'If that email needs verification, a new code is on its way.',
        'reset_link_sent' => 'If that email is registered, a reset link is on its way.',
        'reset_link_invalid' => 'This password reset link is invalid or has expired. Please request a new one.',
        'password_reset' => 'Password reset successfully.',
    ],

    'api_keys' => [
        'password_required' => 'Enter your current password to do this. If you sign in with Google, set a password first with Forgot password.',
        'not_enabled' => 'API access is not enabled for your account. Contact us to enable it.',
        'limit' => 'You can have at most :max active API keys. Revoke one first.',
        'revoked' => 'API key revoked.',
        'webhook_saved' => 'Webhook saved.',
        'webhook_removed' => 'Webhook removed.',
        'webhook_missing' => 'Set a webhook URL first.',
        'webhook_test_sent' => 'Test event delivered (HTTP :status).',
        'webhook_test_failed' => 'Test event was not accepted: :error',
    ],
];
