<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\UserNotification;

class NotificationController extends Controller
{
    /**
     * Get pending credit notifications for the authenticated user
     * Route: GET /user/notifications/credits
     */
    public function getCreditNotifications(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Use database transaction to prevent race conditions
        return DB::transaction(function () use ($user) {
            // Get unread credit notifications with locking to prevent concurrent access
            $notifications = UserNotification::where('users_id', $user->id)
                ->where('read_at', null)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate() // This prevents concurrent access to the same notifications
                ->get();

            if ($notifications->isEmpty()) {
                return response()->json([]);
            }

            $creditNotifications = [];
            $notificationIds = [];

            foreach ($notifications as $notification) {
                $data = json_decode($notification->data, true);

                // Only include credit-related notifications
                if (isset($data['credits_transfer_id'])) {
                    $creditNotifications[] = [
                        'id' => $notification->id,
                        'type' => $this->mapStatusToNotificationType($data['new_status']),
                        'request_id' => $data['credits_transfer_id'],
                        'amount' => $data['amount'],
                        'message' => $data['message'] ?? null,
                        'created_at' => $notification->created_at,
                    ];

                    $notificationIds[] = $notification->id;
                }
            }

            // Mark ALL fetched notifications as read in a single query (more efficient)
            if (!empty($notificationIds)) {
                UserNotification::whereIn('id', $notificationIds)
                    ->update(['read_at' => now()]);
            }

            return response()->json($creditNotifications);
        });
    }

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
     * Map Laravel status ID to frontend notification type
     */
    private function mapStatusToNotificationType($statusId)
    {
        switch ($statusId) {
            case 1:
                return 'credit_approved';
            case 3:
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
            case 1:
                return "Your credit request of $amount has been approved and added to your balance.";
            case 3:
                return "Your credit request of $amount has been rejected. Please contact support for assistance.";
            case 2:
                return "Your credit request of $amount is pending review.";
            default:
                return "Your credit request status has been updated.";
        }
    }
}
