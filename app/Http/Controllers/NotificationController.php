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
            return response()->json(['error' => __('api.unauthorized')], 401);
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

    /**
     * The notification's sentence, in the language of the current request.
     *
     * This used to be a bare `$data['message']` — the English string the factory below
     * rendered at WRITE time. Because it was frozen at creation, an Arabic customer read
     * English, and switching language on the storefront could never change it however
     * many times the page refetched.
     *
     * Rows written from now on carry `message_key` + `message_params` and are translated
     * here under the locale LocaleMiddleware set from the {locale} route segment. Rows
     * written before that (every live notification at the time this shipped) have no
     * key, but the rest of the payload is enough to reconstruct one — status, type,
     * kyc_status — so they translate too. The stored English `message` is only the
     * fallback when nothing can be inferred, or when the key has since been removed
     * from the lang files.
     */
    private static function resolveMessage(array $data, ?string $columnType = null): ?string
    {
        $key = self::messageKeyOf($data, $columnType);

        if (!$key) {
            return $data['message'] ?? null;
        }

        // A missing key makes trans() hand the key itself back. Rather than showing the
        // customer "notifications.credit_approved", fall back to the stored sentence.
        if (!\Illuminate\Support\Facades\Lang::has($key)) {
            return $data['message'] ?? null;
        }

        return self::composeMessage($key, self::messageParamsOf($data, $key));
    }

    /**
     * Which notifications.* line this payload is. Prefer the key written at create
     * time; otherwise reconstruct it from the same fields the factories have always
     * stored (type, kyc_status, order_id, new_status) so pre-key rows translate.
     */
    private static function messageKeyOf(array $data, ?string $columnType = null): ?string
    {
        if (!empty($data['message_key'])) {
            return $data['message_key'];
        }

        foreach ([$data['type'] ?? null, $columnType] as $type) {
            $fromType = self::keyForStoredType($type);
            if ($fromType) {
                return $fromType;
            }
        }

        if (isset($data['kyc_status'])) {
            $status = (int) $data['kyc_status'];
            if ($status === \App\Models\User::VERIFICATION_APPROVED) {
                return 'notifications.kyc_approved';
            }
            if ($status === \App\Models\User::VERIFICATION_REJECTED) {
                return 'notifications.kyc_rejected';
            }
        }

        if (array_key_exists('order_id', $data) && $data['order_id'] !== null) {
            $status = (int) ($data['new_status'] ?? 0);
            if ($status === \App\Order::STATUS_APPROVED) {
                return 'notifications.order_approved';
            }
            if ($status === \App\Order::STATUS_REJECTED) {
                return 'notifications.order_rejected';
            }

            return 'notifications.order_cancelled';
        }

        if (isset($data['credits_transfer_id']) || isset($data['new_status'])) {
            return self::creditMessageKey($data['new_status'] ?? null);
        }

        return null;
    }

    /**
     * Map a stored `type` (column or data.type) onto a lang key.
     *
     * Includes the leftover labels from `2026_08_10_000010`'s backfill (`approved` /
     * `rejected` / `pending` / `kyc` / `general`) so those rows still resolve. `kyc`
     * and `general` are too vague to pick a line on their own — the caller continues
     * to kyc_status / new_status for those.
     */
    private static function keyForStoredType(?string $type): ?string
    {
        return match ($type) {
            'credit_approved', 'approved' => 'notifications.credit_approved',
            'credit_rejected', 'rejected' => 'notifications.credit_rejected',
            'credit_pending', 'pending' => 'notifications.credit_pending',
            'order_approved' => 'notifications.order_approved',
            'order_rejected' => 'notifications.order_rejected',
            'order_cancelled' => 'notifications.order_cancelled',
            'kyc_approved' => 'notifications.kyc_approved',
            'kyc_rejected' => 'notifications.kyc_rejected',
            default => null,
        };
    }

    private static function messageParamsOf(array $data, string $key): array
    {
        $params = $data['message_params'] ?? [];

        if (!array_key_exists('amount', $params) && isset($data['amount'])) {
            $params['amount'] = $data['amount'];
        }

        // Only credit rejections have ever appended the admin reason onto the
        // sentence. Order-cancelled stores a machine reason (`supplier_cancelled`)
        // that was never part of the customer-facing line.
        if (
            $key === 'notifications.credit_rejected'
            && !array_key_exists('reason', $params)
            && filled($data['reason'] ?? null)
        ) {
            $params['reason'] = $data['reason'];
        }

        return $params;
    }

    /**
     * The storefront's visual type. Prefer a type we can derive from the message key
     * so a credit row whose column was backfilled to 'general' (or a KYC row stuck
     * on 'kyc') still renders as an approval/rejection rather than the generic
     * fallback.
     */
    private static function presentType(array $data, ?string $columnType): string
    {
        $key = self::messageKeyOf($data, $columnType);

        if ($key && str_starts_with($key, 'notifications.')) {
            $fromKey = substr($key, strlen('notifications.'));
            $known = [
                'credit_approved', 'credit_rejected', 'credit_pending', 'credit_default',
                'order_approved', 'order_rejected', 'order_cancelled',
                'kyc_approved', 'kyc_rejected',
            ];
            if (in_array($fromKey, $known, true)) {
                return $fromKey;
            }
        }

        return $columnType ?: 'general';
    }

    /**
     * One line, fully assembled: the translated sentence plus, when an admin typed one,
     * the reason appended behind a translated label.
     *
     * Shared by the read path (resolveMessage, current locale) and the write path
     * (renderDefault, app default locale) so the audit copy stored in data['message']
     * says the same thing the customer is served — including the reason, which
     * credits_transfer.rejected_reason has always held and which used to be the only
     * way a customer learned why a top-up was refused.
     */
    private static function composeMessage(string $key, array $params, ?string $locale = null): string
    {
        $message = trans($key, $params, $locale);

        // The admin's free-text reason is in whatever language they typed it in; only
        // the label around it is translated.
        $reason = $params['reason'] ?? null;
        if (filled($reason)) {
            $message .= trans('notifications.reason_suffix', ['reason' => $reason], $locale);
        }

        return $message;
    }

    /** Shape a notification for the API. */
    private function present(UserNotification $notification): array
    {
        $data = self::dataOf($notification);

        return [
            'id' => $notification->id,
            'type' => self::presentType($data, $notification->type),
            'request_id' => $data['credits_transfer_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'message' => self::resolveMessage($data, $notification->type),
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
            return response()->json(['message' => __('auth.unauthenticated'), 'code' => 'unauthenticated'], 401);
        }

        $notification = UserNotification::where('id', $id)
            ->where('users_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => __('api.notifications.not_found'), 'code' => 'not_found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => __('api.notifications.marked_read'),
            'unread_count' => UserNotification::where('users_id', $user->id)->unread()->count(),
        ]);
    }

    /** Mark every unread notification read. Backs the "Mark All as Read" button. */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => __('auth.unauthenticated'), 'code' => 'unauthenticated'], 401);
        }

        $updated = UserNotification::where('users_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => __('api.notifications.all_marked_read'),
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function deleteNotification(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => __('api.unauthorized')], 401);
        }

        try {
            // Find the notification belonging to the authenticated user
            $notification = UserNotification::where('id', $id)
                ->where('users_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json(['error' => __('api.notifications.not_found')], 404);
            }

            // Delete the notification
            $notification->delete();

            return response()->json([
                'message' => __('api.notifications.deleted'),
                'deleted_id' => $id
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => __('api.notifications.delete_failed'),
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
            return response()->json(['error' => __('api.unauthorized')], 401);
        }

        try {
            // Find all read notifications for the user
            $readNotifications = UserNotification::where('users_id', $user->id)
                ->whereNotNull('read_at')
                ->get();

            $deletedCount = $readNotifications->count();

            if ($deletedCount === 0) {
                return response()->json([
                    'message' => __('api.notifications.no_read_found'),
                    'deleted_count' => 0
                ], 200);
            }

            // Delete all read notifications
            UserNotification::where('users_id', $user->id)
                ->whereNotNull('read_at')
                ->delete();

            return response()->json([
                'message' => __('api.notifications.all_read_deleted'),
                'deleted_count' => $deletedCount
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => __('api.notifications.delete_read_failed'),
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // public function getCreditNotifications(Request $request)
    // {
    //     $user = Auth::user();
    //     if (!$user) {
    //         return response()->json(['error' => __('api.unauthorized')], 401);
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
            return response()->json(['error' => __('api.unauthorized')], 401);
        }

        $notification = UserNotification::where('id', $notificationId)
            ->where('users_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['error' => __('api.notifications.not_found')], 404);
        }

        // Mark as read if not already
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json(['message' => __('api.notifications.acknowledged')]);
    }

    /**
     * Create a credit status change notification
     * Call this from your CreditsController after updating status
     */
    public static function createCreditNotification($userId, $creditsTransferId, $newStatus, $previousStatus, $amount, $reason = null)
    {
        $key = self::creditMessageKey($newStatus);
        $params = ['amount' => $amount];

        // credits_transfer.rejected_reason has been collected and editable in the CMS
        // all along and reached the customer nowhere — they were told to "contact
        // support" while the answer was already stored against their row.
        if (filled($reason)) {
            $params['reason'] = $reason;
        }

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => $newStatus,
            // The `type` COLUMN is what present() and scopeOfType() read. Without it
            // the CMS backfill stored 'general' on credit rows (JSON new_status was a
            // string, so the CASE missed it) and the storefront could not tell an
            // approval from a generic ping.
            'type' => str_replace('notifications.', '', $key),
            // Array, not json_encode(): `data` is cast to array on the model, so
            // encoding here stored a double-encoded string that read back as a string.
            'data' => [
                'credits_transfer_id' => $creditsTransferId,
                'new_status' => $newStatus,
                'previous_status' => $previousStatus,
                'amount' => $amount,
                'reason' => $reason,
                'message_key' => $key,
                'message_params' => $params,
                // Rendered in the app default locale. Not what the API serves any more
                // (resolveMessage() re-renders from the key per request), but kept as
                // the audit trail of what was said, and as the fallback if the key is
                // ever removed from the lang files.
                'message' => self::renderDefault($key, $params),
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
        $approved = $newStatus == \App\Models\User::VERIFICATION_APPROVED;
        $key = $approved ? 'notifications.kyc_approved' : 'notifications.kyc_rejected';

        UserNotification::create([
            'users_id' => $userId,
            'statuses_id' => null,
            // The `type` COLUMN is what present() and scopeOfType() read; data.type is
            // not consulted. Without it every KYC notification presented as 'general',
            // and the storefront could not tell an approval from a rejection — it showed
            // both with the neutral 'Verification Update' title and a success icon.
            'type' => $approved ? 'kyc_approved' : 'kyc_rejected',
            // Array, not json_encode(): `data` is cast to array on the model, so
            // encoding here stored a double-encoded string that read back as a string.
            'data' => [
                'type' => $approved ? 'kyc_approved' : 'kyc_rejected',
                'new_status' => null,
                'kyc_status' => $newStatus,
                'previous_kyc_status' => $previousStatus,
                'message_key' => $key,
                'message_params' => [],
                'message' => self::renderDefault($key, []),
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
        $key = 'notifications.order_cancelled';

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
                'message_key' => $key,
                'message_params' => [],
                'message' => self::renderDefault($key, []),
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

        $key = $approved ? 'notifications.order_approved' : 'notifications.order_rejected';

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
                'message_key' => $key,
                'message_params' => [],
                'message' => self::renderDefault($key, []),
            ],
            'read_at' => null,
        ]);
    }

    /**
     * Render a line in the app's DEFAULT locale, whatever the current request happens to
     * be set to.
     *
     * The stored `message` is an audit record of what the customer was told, not what
     * the API serves — resolveMessage() re-renders from the key on every read. Pinning
     * it to the default keeps that record stable: without this, a notification written
     * during an Arabic request would store Arabic and an English one English, purely by
     * accident of who happened to trigger it.
     */
    private static function renderDefault(string $key, array $params): string
    {
        return self::composeMessage($key, $params, config('app.locale'));
    }

    /** Which notifications.* line a credits status maps to. */
    private static function creditMessageKey($statusId): string
    {
        switch ($statusId) {
            case CreditsTransfer::STATUS_APPROVED:
                return 'notifications.credit_approved';
            case CreditsTransfer::STATUS_REJECTED:
                return 'notifications.credit_rejected';
            case CreditsTransfer::STATUS_PENDING:
                return 'notifications.credit_pending';
            default:
                return 'notifications.credit_default';
        }
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
     * Get notifications for polling (doesn't mark as read)
     */
    public function getNotificationsForPolling(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => __('api.unauthorized')], 401);
        }

        // Fetch all notifications for the user, ordered by creation date
        $notifications = UserNotification::where('users_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([]);
        }

        // NOTE: We DON'T mark notifications as read here
        // This is just for polling to check for new notifications.
        //
        // Built through present() rather than inline: this block duplicated that
        // shape and drifted from it (it never read the `type` COLUMN, so every
        // order notification polled as a credit one), and it read data['message']
        // directly, which is the untranslated write-time string.
        return response()->json(
            $notifications->map(fn ($n) => $this->present($n))->values()
        );
    }

    /**
     * Get only credit-related notifications for polling
     * This endpoint is specifically for the polling hook and doesn't mark notifications as read
     */
    public function getCreditNotifications(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => __('api.unauthorized')], 401);
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

        // NOTE: We DON'T mark notifications as read here
        // This is specifically for polling credit notifications only.
        // Same reasoning as getNotificationsForPolling() above: shape comes from
        // present() so it cannot drift, and the message is translated per request.
        return response()->json(
            $notifications->map(fn ($n) => $this->present($n))->values()
        );
    }
}
