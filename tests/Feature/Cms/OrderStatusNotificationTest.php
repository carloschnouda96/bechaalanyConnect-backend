<?php

namespace Tests\Feature\Cms;

use App\CreditsTransfer;
use App\Models\User;
use App\Order;
use App\UserNotification;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

/**
 * An order decision that the customer never hears about.
 *
 * Credits were debited at placement, so from the customer's side an approved order was
 * indistinguishable from one nobody had looked at. Cms\OrdersController sent no email
 * and wrote no notification; the only signal was the My Orders page refreshing every
 * three minutes. Manual review of every order is deliberate here, which makes telling
 * the customer the decision part of the job.
 *
 * The bulk case matters most: it is the path that decides many orders at once, and it
 * has to owe each of them a message.
 */
class OrderStatusNotificationTest extends TestCase
{
    use CreatesCatalog;

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Notify Admin';
        $admin->email = 'notify_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(float $balance = 0.0): User
    {
        return User::create([
            'username' => 'notify_' . uniqid(),
            'email' => 'notify_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => $balance,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function order(User $user, float $total, int $status = Order::STATUS_PENDING): Order
    {
        return Order::create([
            'users_id' => $user->id,
            'product_variation_id' => $this->createVariation($total)->id,
            'quantity' => 1,
            'total_price' => $total,
            'statuses_id' => $status,
            'credits_applied_status' => $status,
        ]);
    }

    private function url(string $path = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/orders' . $path;
    }

    /**
     * The payload the CMS edit form actually submits.
     *
     * The vendor's update() validates every non-nullable registered field, so a
     * status-only PUT is a 422 and never reaches the money code at all.
     */
    private function editPayload(Order $order, int $status): array
    {
        return [
            'users_id' => $order->users_id,
            'product_variation_id' => $order->product_variation_id,
            'quantity' => $order->quantity,
            'total_price' => $order->total_price,
            'statuses_id' => $status,
        ];
    }

    private function notificationsFor(User $user): \Illuminate\Support\Collection
    {
        return UserNotification::where('users_id', $user->id)->get();
    }

    /**
     * Messages the array transport actually collected.
     *
     * Mail::fake() is no help here: these are raw Mail::send('view', ...) calls, not
     * Mailables, so assertSent() cannot see them. phpunit.xml sets MAIL_MAILER=array,
     * so the transport itself is the honest record of what was sent.
     */
    private function sentMessages(): \Illuminate\Support\Collection
    {
        return collect(Mail::mailer()->getSymfonyTransport()->messages());
    }

    private function flushMail(): void
    {
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    public function test_approving_notifies_and_emails_the_customer(): void
    {
        $this->flushMail();

        $user = $this->user(50.00);
        $order = $this->order($user, 10.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $order->id), $this->editPayload($order, Order::STATUS_APPROVED));

        $notifications = $this->notificationsFor($user);

        $this->assertCount(1, $notifications);
        $this->assertSame('order_approved', $notifications->first()->type);
        $this->assertSame($order->id, $notifications->first()->data['order_id']);

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_rejecting_notifies_and_emails_the_customer(): void
    {
        $this->flushMail();

        $user = $this->user(50.00);
        $order = $this->order($user, 10.00);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $order->id), $this->editPayload($order, Order::STATUS_REJECTED));

        $notifications = $this->notificationsFor($user);

        $this->assertCount(1, $notifications);
        $this->assertSame('order_rejected', $notifications->first()->type);

        $this->assertCount(1, $this->sentMessages());
    }

    /** A save that changes nothing must not spam the customer. */
    public function test_a_no_op_save_sends_nothing(): void
    {
        $this->flushMail();

        $user = $this->user(50.00);
        $order = $this->order($user, 10.00, Order::STATUS_APPROVED);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $order->id), $this->editPayload($order, Order::STATUS_APPROVED));

        $this->assertCount(0, $this->notificationsFor($user));
        $this->assertCount(0, $this->sentMessages());
    }

    /** Moving an order back to pending is not a decision worth mailing about. */
    public function test_moving_back_to_pending_sends_nothing(): void
    {
        $this->flushMail();

        $user = $this->user(50.00);
        $order = $this->order($user, 10.00, Order::STATUS_APPROVED);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $order->id), $this->editPayload($order, Order::STATUS_PENDING));

        $this->assertCount(0, $this->notificationsFor($user));
        $this->assertCount(0, $this->sentMessages());
    }

    /** The whole point: N bulk-approved orders owe N customers a message. */
    public function test_bulk_approve_notifies_every_order(): void
    {
        $this->flushMail();

        $user = $this->user(200.00);
        $ids = collect(range(1, 3))->map(fn () => $this->order($user, 10.00)->id);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/bulk-status'), [
                'ids' => $ids->implode(','),
                'statuses_id' => Order::STATUS_APPROVED,
            ]);

        $notifications = $this->notificationsFor($user);

        $this->assertCount(3, $notifications);
        $this->assertEqualsCanonicalizing(
            $ids->all(),
            $notifications->map(fn ($n) => $n->data['order_id'])->all()
        );
        $this->assertCount(3, $this->sentMessages());
    }

    /** A rejected top-up now carries the reason the admin already typed in. */
    public function test_rejected_topup_delivers_the_reason(): void
    {
        $this->flushMail();

        $user = $this->user();

        $transfer = CreditsTransfer::create([
            'users_id' => $user->id,
            'amount' => 25.00,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);

        // The reason is typed on the edit form; rejection itself goes through the bulk
        // action, the other real admin path, so this does not have to satisfy the
        // vendor form's required receipt_image upload.
        $transfer->rejected_reason = 'The receipt shows a different amount.';
        $transfer->saveQuietly();

        $this->actingAs($this->admin(), 'admin')
            ->put('/' . config('hellotree.cms_route_prefix') . '/credits-transfer/bulk-status', [
                'ids' => (string) $transfer->id,
                'statuses_id' => CreditsTransfer::STATUS_REJECTED,
            ]);

        $notification = $this->notificationsFor($user)->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('The receipt shows a different amount.', $notification->data['message']);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('The receipt shows a different amount.', (string) $messages->first()->getOriginalMessage()->getHtmlBody());
    }
}
