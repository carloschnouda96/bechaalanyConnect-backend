<?php

namespace Tests\Feature;

use App\CreditsTransfer;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\UserNotification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * In-app notifications are written once and read many times, in either language.
 *
 * They used to be rendered to an English sentence at WRITE time and frozen into
 * user_notifications.data['message'], which meant an Arabic customer read English and
 * no amount of refetching could change it — the storefront's language switch was
 * structurally unable to translate them. The factories now store a translation key
 * plus its parameters, and NotificationController::present() resolves it under the
 * request locale.
 *
 * The legacy case matters just as much as the new one: there are rows in production
 * that only ever carried `message`, and this change ships without a backfill, so they
 * must keep reading exactly as they always did.
 */
class NotificationLocalizationTest extends TestCase
{
    private function user(): User
    {
        return User::create([
            'username' => 'notify_' . uniqid(),
            'email' => 'notify_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    /** The single notification the endpoint returns, for the given locale. */
    private function fetchOne(User $user, string $locale): array
    {
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/{$locale}/user/notifications");
        $response->assertOk();

        $notifications = $response->json('notifications');
        $this->assertCount(1, $notifications);

        return $notifications[0];
    }

    public function test_a_credit_notification_reads_in_the_requested_locale(): void
    {
        $user = $this->user();

        NotificationController::createCreditNotification(
            $user->id,
            null,
            CreditsTransfer::STATUS_APPROVED,
            CreditsTransfer::STATUS_PENDING,
            '25.00'
        );

        $this->assertSame(
            __('notifications.credit_approved', ['amount' => '25.00'], 'en'),
            $this->fetchOne($user, 'en')['message']
        );

        $this->assertSame(
            __('notifications.credit_approved', ['amount' => '25.00'], 'ar'),
            $this->fetchOne($user, 'ar')['message']
        );
    }

    public function test_the_admin_reason_is_appended_with_a_translated_label(): void
    {
        $user = $this->user();

        NotificationController::createCreditNotification(
            $user->id,
            null,
            CreditsTransfer::STATUS_REJECTED,
            CreditsTransfer::STATUS_PENDING,
            '10.00',
            'Receipt was unreadable'
        );

        $arabic = $this->fetchOne($user, 'ar')['message'];

        // The label is translated; the admin's own words are passed through untouched,
        // because they are in whatever language the admin typed them in.
        $this->assertStringContainsString(__('notifications.credit_rejected', ['amount' => '10.00'], 'ar'), $arabic);
        $this->assertStringContainsString('Receipt was unreadable', $arabic);
        $this->assertStringNotContainsString('Reason:', $arabic);
    }

    public function test_an_order_decision_reads_in_the_requested_locale(): void
    {
        $user = $this->user();

        NotificationController::createOrderStatusNotification(
            $user->id,
            null,
            \App\Order::STATUS_REJECTED,
            \App\Order::STATUS_PENDING
        );

        $this->assertSame('order_rejected', $this->fetchOne($user, 'en')['type']);
        $this->assertSame(__('notifications.order_rejected', [], 'ar'), $this->fetchOne($user, 'ar')['message']);
    }

    /**
     * createKycNotification wrote no `type` column, so present() fell through to
     * 'general' and the storefront could not tell an approval from a rejection — it
     * showed both with a neutral title and a green success icon.
     */
    public function test_a_kyc_decision_carries_a_type_the_storefront_can_distinguish(): void
    {
        $approvedUser = $this->user();
        NotificationController::createKycNotification(
            $approvedUser->id,
            User::VERIFICATION_APPROVED,
            User::VERIFICATION_PENDING
        );
        $this->assertSame('kyc_approved', $this->fetchOne($approvedUser, 'en')['type']);

        $rejectedUser = $this->user();
        NotificationController::createKycNotification(
            $rejectedUser->id,
            User::VERIFICATION_REJECTED,
            User::VERIFICATION_PENDING
        );

        $rejected = $this->fetchOne($rejectedUser, 'ar');
        $this->assertSame('kyc_rejected', $rejected['type']);
        $this->assertSame(__('notifications.kyc_rejected', [], 'ar'), $rejected['message']);
    }

    /**
     * Rows written before message_key existed still have to translate. Every live
     * notification currently looks like this — frozen English `message`, no key —
     * so serving the stored sentence would mean the lang files never run for anyone
     * who already has a notification. The payload still has enough to reconstruct
     * the key (status + amount, or type, or kyc_status).
     */
    public function test_a_legacy_credit_row_translates_from_its_status(): void
    {
        $user = $this->user();

        // Shape of the live credit rows: type column was backfilled to 'general'
        // (JSON new_status was a string, so the CASE missed it) and there is no
        // message_key. present() used to return that English sentence in both
        // locales, and to present the type as 'general' because the column was
        // truthy and short-circuited the status map.
        UserNotification::create([
            'users_id' => $user->id,
            'statuses_id' => CreditsTransfer::STATUS_APPROVED,
            'type' => 'general',
            'data' => [
                'credits_transfer_id' => '30',
                'new_status' => '1',
                'previous_status' => 3,
                'amount' => 50,
                'message' => 'Your credit request of 50 has been approved and added to your balance.',
            ],
            'read_at' => null,
        ]);

        $english = $this->fetchOne($user, 'en');
        $this->assertSame('credit_approved', $english['type']);
        $this->assertSame(
            __('notifications.credit_approved', ['amount' => 50], 'en'),
            $english['message']
        );

        $arabic = $this->fetchOne($user, 'ar');
        $this->assertSame('credit_approved', $arabic['type']);
        $this->assertSame(
            __('notifications.credit_approved', ['amount' => 50], 'ar'),
            $arabic['message']
        );
    }

    public function test_a_legacy_kyc_row_translates_from_its_kyc_status(): void
    {
        $user = $this->user();

        UserNotification::create([
            'users_id' => $user->id,
            'statuses_id' => null,
            'type' => 'kyc',
            'data' => [
                'type' => 'kyc',
                'kyc_status' => User::VERIFICATION_APPROVED,
                'new_status' => null,
                'previous_kyc_status' => User::VERIFICATION_PENDING,
                'message' => 'Your account has been verified. You can now use all platform features.',
            ],
            'read_at' => null,
        ]);

        $arabic = $this->fetchOne($user, 'ar');
        $this->assertSame('kyc_approved', $arabic['type']);
        $this->assertSame(__('notifications.kyc_approved', [], 'ar'), $arabic['message']);
    }

    public function test_a_legacy_order_cancelled_row_translates_from_its_type(): void
    {
        $user = $this->user();

        UserNotification::create([
            'users_id' => $user->id,
            'statuses_id' => \App\Order::STATUS_REJECTED,
            'type' => 'order_cancelled',
            'data' => [
                'type' => 'order_cancelled',
                'order_id' => 4,
                'amount' => '4.86',
                'reason' => 'supplier_cancelled',
                'message' => 'Your order was cancelled by the supplier and your credits have been refunded.',
            ],
            'read_at' => null,
        ]);

        $this->assertSame(
            __('notifications.order_cancelled', [], 'ar'),
            $this->fetchOne($user, 'ar')['message']
        );
    }

    /**
     * A free-text row with nothing to infer a key from (an admin-typed CMS
     * notification, a quarantined payload) must keep reading its stored sentence
     * rather than showing a raw translation key or null.
     */
    public function test_an_opaque_legacy_row_still_reads_its_stored_message(): void
    {
        $user = $this->user();

        UserNotification::create([
            'users_id' => $user->id,
            'statuses_id' => null,
            'type' => 'general',
            'data' => [
                'message' => 'A one-off note the admin typed by hand.',
            ],
            'read_at' => null,
        ]);

        foreach (['en', 'ar'] as $locale) {
            $this->assertSame(
                'A one-off note the admin typed by hand.',
                $this->fetchOne($user, $locale)['message']
            );
        }
    }

    public function test_a_credit_notification_sets_a_type_the_storefront_can_distinguish(): void
    {
        $user = $this->user();

        NotificationController::createCreditNotification(
            $user->id,
            null,
            CreditsTransfer::STATUS_APPROVED,
            CreditsTransfer::STATUS_PENDING,
            '25.00'
        );

        $this->assertSame('credit_approved', $this->fetchOne($user, 'en')['type']);
    }

    /** A key that is no longer in the lang files must not leak to the customer. */
    public function test_an_unknown_key_falls_back_to_the_stored_message(): void
    {
        $user = $this->user();

        UserNotification::create([
            'users_id' => $user->id,
            'statuses_id' => null,
            'type' => 'general',
            'data' => [
                'message_key' => 'notifications.a_key_that_was_removed',
                'message_params' => [],
                'message' => 'A sentence written when that key still existed.',
            ],
            'read_at' => null,
        ]);

        $this->assertSame(
            'A sentence written when that key still existed.',
            $this->fetchOne($user, 'ar')['message']
        );
    }
}
