<?php

namespace Tests\Feature\Cms;

use App\CreditsTransfer;
use App\Models\User;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Receipts moved to the private disk, but the CMS kept rendering them with
 * Storage::url() against the default (public) disk — so every receipt showed as a
 * broken image on the one screen where a top-up is approved, and receipt upload is the
 * only way credits enter the system. This route is now the only way the CMS can show a
 * receipt at all; if it breaks, top-ups are being approved blind.
 */
class CreditsReceiptTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Receipt Admin';
        $admin->email = 'receipt_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function user(): User
    {
        return User::create([
            'username' => 'receipt_' . uniqid(),
            'email' => 'receipt_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);
    }

    private function transfer(?string $receipt = 'receipts/proof.png'): CreditsTransfer
    {
        return CreditsTransfer::create([
            'users_id' => $this->user()->id,
            'amount' => 25.00,
            'receipt_image' => $receipt,
            'statuses_id' => CreditsTransfer::STATUS_PENDING,
            'credits_applied_status' => CreditsTransfer::STATUS_PENDING,
        ]);
    }

    private function url(int $id): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/credits-transfer/' . $id . '/receipt';
    }

    public function test_receipt_is_streamed_from_the_private_disk(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('receipts/proof.png', UploadedFile::fake()->image('proof.png')->getContent());

        $transfer = $this->transfer();

        $response = $this->actingAs($this->admin(), 'admin')->get($this->url($transfer->id));

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** Installs that predate `php artisan uploads:privatize` still have them on public. */
    public function test_receipt_falls_back_to_the_public_disk(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        Storage::disk('public')->put('receipts/proof.png', UploadedFile::fake()->image('proof.png')->getContent());

        $transfer = $this->transfer();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url($transfer->id))
            ->assertOk();
    }

    public function test_receipt_is_not_reachable_without_an_admin_session(): void
    {
        $transfer = $this->transfer();

        $this->get($this->url($transfer->id))->assertRedirect();
    }

    public function test_a_transfer_with_no_receipt_is_a_404(): void
    {
        $transfer = $this->transfer(null);

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url($transfer->id))
            ->assertNotFound();
    }

    /** A row pointing at a file that is gone must 404, not 500. */
    public function test_a_missing_file_is_a_404(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        $transfer = $this->transfer();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url($transfer->id))
            ->assertNotFound();
    }

    /**
     * The list screen must link the streaming route, never Storage::url(): the latter
     * mints /storage/receipts/... which does not resolve for a private file.
     */
    public function test_the_list_screen_links_the_streaming_route(): void
    {
        $transfer = $this->transfer();

        $this->actingAs($this->admin(), 'admin')
            ->get('/' . config('hellotree.cms_route_prefix') . '/credits-transfer')
            ->assertOk()
            ->assertSee($this->url($transfer->id), false)
            ->assertDontSee('/storage/receipts/', false);
    }
}
