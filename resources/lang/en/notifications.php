<?php

/*
|--------------------------------------------------------------------------
| In-app notification messages
|--------------------------------------------------------------------------
|
| These used to be rendered to a string at WRITE time and frozen into
| user_notifications.data['message'], so a customer saw English no matter which
| language they were browsing in, and switching language could never change it.
| The factories in NotificationController now store a `message_key` naming one of
| these lines plus `message_params`, and present() resolves it under the request
| locale (the read endpoints are all under /{locale}, so App::setLocale is already
| correct there). Rows written before message_key existed still resolve: present()
| reconstructs the key from type / kyc_status / new_status so a language switch
| re-renders them too.
|
| Keep this file and resources/lang/ar/notifications.php in sync, key for key.
|
*/

return [
    'credit_approved' => 'Your credit request of :amount has been approved and added to your balance.',
    'credit_rejected' => 'Your credit request of :amount has been rejected. Please contact support for assistance.',
    'credit_pending' => 'Your credit request of :amount is pending review.',
    'credit_default' => 'Your credit request status has been updated.',

    'kyc_approved' => 'Your account has been verified. You can now use all platform features.',
    'kyc_rejected' => 'Your verification documents were rejected. Please resubmit your documents.',

    'order_approved' => 'Your order has been approved. Open My Orders to see your code or delivery details.',
    'order_rejected' => 'Your order was rejected and the credits have been returned to your balance.',
    'order_cancelled' => 'Your order was cancelled by the supplier and your credits have been refunded.',

    'general' => 'You have a new notification.',

    /*
     * Appended when an admin typed a reason. The reason itself is free text in
     * whatever language the admin wrote it — only the label is translated.
     */
    'reason_suffix' => ' Reason: :reason',
];
