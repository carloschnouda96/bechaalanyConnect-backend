<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Get unread credit notifications
        $notifications = UserNotification::where('users_id', $user->id)
            // ->where('type', 'credit_status_change')
            ->where('read_at', null)
            ->orderBy('created_at', 'desc')
            ->get();

        $creditNotifications = [];

        foreach ($notifications as $notification) {
            $data = json_decode($notification->data, true);

            $creditNotifications[] = [
                'id' => $notification->id,
                'type' => $this->mapStatusToNotificationType($data['new_status']),
                'request_id' => $data['credits_transfer_id'],
                'amount' => $data['amount'],
                'message' => $data['message'] ?? null,
                'created_at' => $notification->created_at,
            ];

            // Mark as read
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json($creditNotifications);
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
