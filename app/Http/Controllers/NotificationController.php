<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\CreditsTransfer;
use App\UserNotification;

class NotificationController extends Controller
{
    /**
     * Get pending credit notifications for the authenticated user
     * Route: GET /user/notifications/credits
     */


    public function getAllNotifications(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Fetch all notifications for the user, ordered by creation date
        $notifications = UserNotification::where('users_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([]);
        }

        $allNotifications = [];
        $notificationIds = [];

        foreach ($notifications as $notification) {
            $data = json_decode($notification->data, true);

            $allNotifications[] = [
                'id' => $notification->id,
                'type' => isset($data['new_status']) ? $this->mapStatusToNotificationType($data['new_status']) : 'general',
                'request_id' => $data['credits_transfer_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'message' => $data['message'] ?? null,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at,
            ];

            $notificationIds[] = $notification->id;
        }

        // Mark ALL fetched notifications as read in a single query (more efficient)
        if (!empty($notificationIds)) {
            UserNotification::whereIn('id', $notificationIds)
                ->update(['read_at' => now()]);
        }

        return response()->json($allNotifications);
    }

    public function deleteNotification(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            // Find the notification belonging to the authenticated user
            $notification = UserNotification::where('id', $id)
                ->where('users_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json(['error' => 'Notification not found'], 404);
            }

            // Delete the notification
            $notification->delete();

            return response()->json([
                'message' => 'Notification deleted successfully',
                'deleted_id' => $id
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete notification',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete all read notifications for the authenticated user
     */
    public function deleteAllRead(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            // Find all read notifications for the user
            $readNotifications = UserNotification::where('users_id', $user->id)
                ->whereNotNull('read_at')
                ->get();

            $deletedCount = $readNotifications->count();

            if ($deletedCount === 0) {
                return response()->json([
                    'message' => 'No read notifications found',
                    'deleted_count' => 0
                ], 200);
            }

            // Delete all read notifications
            UserNotification::where('users_id', $user->id)
                ->whereNotNull('read_at')
                ->delete();

            return response()->json([
                'message' => 'All read notifications deleted successfully',
                'deleted_count' => $deletedCount
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete read notifications',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // public function getCreditNotifications(Request $request)
    // {
    //     $user = Auth::user();
    //     if (!$user) {
    //         return response()->json(['error' => 'Unauthorized'], 401);
    //     }

    //     // Use database transaction to prevent race conditions
    //     return DB::transaction(function () use ($user) {
    //         // Get unread credit notifications with locking to prevent concurrent access
    //         $notifications = UserNotification::where('users_id', $user->id)
    //             ->where('read_at', null)
    //             ->orderBy('created_at', 'desc')
    //             ->lockForUpdate() // This prevents concurrent access to the same notifications
    //             ->get();

    //         if ($notifications->isEmpty()) {
    //             return response()->json([]);
    //         }

    //         $creditNotifications = [];
    //         $notificationIds = [];

    //         foreach ($notifications as $notification) {
    //             $data = json_decode($notification->data, true);

    //             // Only include credit-related notifications
    //             if (isset($data['credits_transfer_id'])) {
    //                 $creditNotifications[] = [
    //                     'id' => $notification->id,
    //                     'type' => $this->mapStatusToNotificationType($data['new_status']),
    //                     'request_id' => $data['credits_transfer_id'],
    //                     'amount' => $data['amount'],
    //                     'message' => $data['message'] ?? null,
    //                     'created_at' => $notification->created_at,
    //                 ];

    //                 $notificationIds[] = $notification->id;
    //             }
    //         }

    //         // Mark ALL fetched notifications as read in a single query (more efficient)
    //         if (!empty($notificationIds)) {
    //             UserNotification::whereIn('id', $notificationIds)
    //                 ->update(['read_at' => now()]);
    //         }

    //         return response()->json($creditNotifications);
    //     });
    // }

    /**
     * Acknowledge a specific notification (optional endpoint for extra safety)
     * Route: POST /user/notifications/{id}/acknowledge
     */
    public function acknowledgeNotification(Request $request, $notificationId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = UserNotification::where('id', $notificationId)
            ->where('users_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        // Mark as read if not already
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json(['message' => 'Notification acknowledged']);
    }

    /**
     * Create a credit status change notification
     * Call this from your CreditsController after updating status
     */
    public static function createCreditNotification($userId, $creditsTransferId, $newStatus, $previousStatus, $amount)
    {
        $message = self::generateStatusMessage($newStatus, $amount);

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => $newStatus,
            'data' => json_encode([
                'credits_transfer_id' => $creditsTransferId,
                'new_status' => $newStatus,
                'previous_status' => $previousStatus,
                'amount' => $amount,
                'message' => $message,
            ]),
            'read_at' => null,
        ]);
    }

    /**
     * Create a KYC verification status change notification
     * Called from the CMS UsersController when an admin approves/rejects documents
     */
    public static function createKycNotification($userId, $newStatus, $previousStatus)
    {
        $message = $newStatus == \App\Models\User::VERIFICATION_APPROVED
            ? 'Your account has been verified. You can now use all platform features.'
            : 'Your verification documents were rejected. Please resubmit your documents.';

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => null,
            'data' => json_encode([
                'type' => 'kyc',
                'new_status' => null,
                'kyc_status' => $newStatus,
                'previous_kyc_status' => $previousStatus,
                'message' => $message,
            ]),
            'read_at' => null,
        ]);
    }

    /**
     * Create a notification when a supplier-sourced order is auto-cancelled
     * (the supplier rejected/cancelled it) and the customer's credits were
     * refunded. Called from SupplierOrderFulfillment::refund().
     */
    public static function createOrderNotification($userId, $orderId, $amount = null, $reason = null)
    {
        $message = 'Your order was cancelled by the supplier and your credits have been refunded.';

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => \App\Order::STATUS_REJECTED,
            'data' => json_encode([
                'type' => 'order_cancelled',
                'order_id' => $orderId,
                'amount' => $amount,
                'reason' => $reason,
                'message' => $message,
            ]),
            'read_at' => null,
        ]);
    }

    /**
     * Map Laravel status ID to frontend notification type
     */
    private function mapStatusToNotificationType($statusId)
    {
        switch ($statusId) {
            case CreditsTransfer::STATUS_APPROVED:
                return 'credit_approved';
            case CreditsTransfer::STATUS_REJECTED:
                return 'credit_rejected';
            default:
                return 'credit_pending';
        }
    }

    /**
     * Generate user-friendly status messages
     */
    private static function generateStatusMessage($statusId, $amount)
    {
        switch ($statusId) {
            case CreditsTransfer::STATUS_APPROVED:
                return "Your credit request of $amount has been approved and added to your balance.";
            case CreditsTransfer::STATUS_REJECTED:
                return "Your credit request of $amount has been rejected. Please contact support for assistance.";
            case CreditsTransfer::STATUS_PENDING:
                return "Your credit request of $amount is pending review.";
            default:
                return "Your credit request status has been updated.";
        }
    }

    /**
     * Get notifications for polling (doesn't mark as read)
     */
    public function getNotificationsForPolling(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Fetch all notifications for the user, ordered by creation date
        $notifications = UserNotification::where('users_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([]);
        }

        $allNotifications = [];

        foreach ($notifications as $notification) {
            $data = json_decode($notification->data, true);

            $allNotifications[] = [
                'id' => $notification->id,
                'type' => isset($data['new_status']) ? $this->mapStatusToNotificationType($data['new_status']) : 'general',
                'request_id' => $data['credits_transfer_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'message' => $data['message'] ?? null,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at,
            ];
        }

        // NOTE: We DON'T mark notifications as read here
        // This is just for polling to check for new notifications

        return response()->json($allNotifications);
    }

    /**
     * Get only credit-related notifications for polling
     * This endpoint is specifically for the polling hook and doesn't mark notifications as read
     */
    public function getCreditNotifications(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Fetch only credit-related notifications for the user
        $notifications = UserNotification::where('users_id', $user->id)
            ->whereNotNull('data')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($notification) {
                $data = json_decode($notification->data, true);
                // Only return notifications that have credits_transfer_id (credit-related)
                return isset($data['credits_transfer_id']) && !empty($data['credits_transfer_id']);
            });

        if ($notifications->isEmpty()) {
            return response()->json([]);
        }

        $creditNotifications = [];

        foreach ($notifications as $notification) {
            $data = json_decode($notification->data, true);

            $creditNotifications[] = [
                'id' => $notification->id,
                'type' => isset($data['new_status']) ? $this->mapStatusToNotificationType($data['new_status']) : 'general',
                'request_id' => $data['credits_transfer_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'message' => $data['message'] ?? null,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at,
            ];
        }

        // NOTE: We DON'T mark notifications as read here
        // This is specifically for polling credit notifications only

        return response()->json($creditNotifications);
    }
}
