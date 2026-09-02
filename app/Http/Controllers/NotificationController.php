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

        $perPage = min((int) $request->get('limit', 20), 50);

        $query = UserNotification::where('users_id', $user->id);

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        if ($request->filled('type')) {
            $query->ofType($request->get('type'));
        }

        // Paginated. This used to ->get() every notification a user had ever
        // received, and the frontend's "Load More" then sliced that same array.
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'notifications' => collect($notifications->items())->map(fn ($n) => $this->present($n))->all(),
            'unread_count' => UserNotification::where('users_id', $user->id)->unread()->count(),
            'total' => $notifications->total(),
            'current_page' => $notifications->currentPage(),
            'per_page' => $notifications->perPage(),
            'last_page' => $notifications->lastPage(),
        ]);

        /*
         | NOTE: fetching no longer marks anything read.
         |
         | This endpoint used to UPDATE read_at on every notification it returned, so
         | simply opening the notifications page marked the lot as read. The unread
         | count was therefore always zero, the bell could never show a badge, and the
         | "Mark All as Read" button was decorative. Reading is now an explicit action:
         | markAsRead() / markAllAsRead() below.
         */
    }

    /**
     * A notification's payload as an array, however it happens to be stored.
     *
     * `user_notifications.data` is cast to array on the model, but the older factories
     * hand create() a json_encode()d string, which the cast then encodes again — so
     * historical rows read back as a JSON string while correctly-written ones read back
     * as an array. Callers assuming one broke on the other: a bare
     * json_decode($notification->data, true) is a TypeError under PHP 8 the moment the
     * value is a real array, and /user/notifications/poll and
     * /user/notifications/credits both did exactly that over EVERY notification a user
     * has — so one correctly-stored row would have 500'd both endpoints.
     */
    private static function dataOf(UserNotification $notification): array
    {
        $data = $notification->data;

        if (is_array($data)) {
            return $data;
        }

        return json_decode((string) $data, true) ?: [];
    }

    /** Shape a notification for the API. */
    private function present(UserNotification $notification): array
    {
        $data = self::dataOf($notification);

        return [
            'id' => $notification->id,
            'type' => $notification->type
                ?: (isset($data['new_status']) ? $this->mapStatusToNotificationType($data['new_status']) : 'general'),
            'request_id' => $data['credits_transfer_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'message' => $data['message'] ?? null,
            'created_at' => $notification->created_at,
            'read_at' => $notification->read_at,
        ];
    }

    /**
     * Mark one notification read.
     *
     * routes/api.php has pointed at this method since the notifications feature was
     * written, but it did not exist — every call was a 500.
     */
    public function markAsRead(Request $request, $id)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        $notification = UserNotification::where('id', $id)
            ->where('users_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.', 'code' => 'not_found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => UserNotification::where('users_id', $user->id)->unread()->count(),
        ]);
    }

    /** Mark every unread notification read. Backs the "Mark All as Read" button. */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        $updated = UserNotification::where('users_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $updated,
            'unread_count' => 0,
        ]);
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
    public static function createCreditNotification($userId, $creditsTransferId, $newStatus, $previousStatus, $amount, $reason = null)
    {
        $message = self::generateStatusMessage($newStatus, $amount);

        // credits_transfer.rejected_reason has been collected and editable in the CMS
        // all along and reached the customer nowhere — they were told to "contact
        // support" while the answer was already stored against their row.
        if (filled($reason)) {
            $message .= ' Reason: ' . $reason;
        }

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => $newStatus,
            // Array, not json_encode(): `data` is cast to array on the model, so
            // encoding here stored a double-encoded string that read back as a string.
            'data' => [
                'credits_transfer_id' => $creditsTransferId,
                'new_status' => $newStatus,
                'previous_status' => $previousStatus,
                'amount' => $amount,
                'reason' => $reason,
                'message' => $message,
            ],
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
            // Array, not json_encode(): `data` is cast to array on the model, so
            // encoding here stored a double-encoded string that read back as a string.
            'data' => [
                'type' => 'kyc',
                'new_status' => null,
                'kyc_status' => $newStatus,
                'previous_kyc_status' => $previousStatus,
                'message' => $message,
            ],
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
            // The `type` COLUMN is what present() and scopeOfType() read; data.type is
            // not consulted. Without it this notification presented as 'general'.
            'type' => 'order_cancelled',
            // Array, not json_encode(): `data` is cast to array on the model, so
            // encoding here stored a double-encoded string that read back as a string.
            'data' => [
                'type' => 'order_cancelled',
                'order_id' => $orderId,
                'amount' => $amount,
                'reason' => $reason,
                'message' => $message,
            ],
            'read_at' => null,
        ]);
    }

    /**
     * An admin decided an order.
     *
     * Nothing told a customer their order had been approved or rejected. They had
     * already been debited at placement, so from their side an approved order was
     * indistinguishable from one nobody had looked at — the only way to find out was to
     * sit on the My Orders page, which refreshes every three minutes. Reviewing every
     * order by hand is deliberate here, which makes telling the customer the decision
     * part of the job, not a nicety.
     *
     * Separate from createOrderNotification() above, which is the supplier's
     * auto-cancellation and says something different.
     */
    public static function createOrderStatusNotification($userId, $orderId, $newStatus, $previousStatus, $amount = null)
    {
        $approved = (int) $newStatus === \App\Order::STATUS_APPROVED;

        $message = $approved
            ? 'Your order has been approved. Open My Orders to see your code or delivery details.'
            : 'Your order was rejected and the credits have been returned to your balance.';

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => $newStatus,
            'type' => $approved ? 'order_approved' : 'order_rejected',
            // Passed as an array, not json_encode()d: `data` is cast to array on the
            // model, so encoding here would store a double-encoded string and every
            // read would hand callers a string back.
            'data' => [
                'type' => $approved ? 'order_approved' : 'order_rejected',
                'order_id' => $orderId,
                'new_status' => $newStatus,
                'previous_status' => $previousStatus,
                'amount' => $amount,
                'message' => $message,
            ],
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
            $data = self::dataOf($notification);

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
                $data = self::dataOf($notification);
                // Only return notifications that have credits_transfer_id (credit-related)
                return isset($data['credits_transfer_id']) && !empty($data['credits_transfer_id']);
            });

        if ($notifications->isEmpty()) {
            return response()->json([]);
        }

        $creditNotifications = [];

        foreach ($notifications as $notification) {
            $data = self::dataOf($notification);

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
