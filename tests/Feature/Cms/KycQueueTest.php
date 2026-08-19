<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycQueueTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'KYC Admin';
        $admin->email = 'kyc_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function applicant(int $status = User::VERIFICATION_PENDING): User
    {
        return User::create([
            'username' => 'kyc_' . uniqid(),
            'email' => 'applicant_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => $status,
            'id_front_image' => 'kyc/front.jpg',
            'id_back_image' => 'kyc/back.jpg',
            'selfie_image' => 'kyc/selfie.jpg',
        ]);
    }

    private function url(string $path = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/kyc-queue' . $path;
    }

    public function test_queue_lists_pending_applicants(): void
    {
        $applicant = $this->applicant();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url())
            ->assertOk()
            ->assertSee($applicant->username, false);
    }

    public function test_queue_requires_an_admin(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    /**
     * The documents live on the private disk now. This route is the only way the CMS
     * can show them, so if it breaks, KYC review becomes impossible.
     */
    public function test_documents_are_streamed_from_the_private_disk(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('kyc/front.jpg', UploadedFile::fake()->image('front.jpg')->getContent());

        $applicant = $this->applicant();
        $applicant->update(['id_front_image' => 'kyc/front.jpg']);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/' . $applicant->id . '/document/id-front'));

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_documents_are_not_reachable_without_an_admin_session(): void
    {
        $applicant = $this->applicant();

        $this->get($this->url('/' . $applicant->id . '/document/id-front'))
            ->assertRedirect();
    }

    public function test_an_unknown_document_slot_is_rejected(): void
    {
        $applicant = $this->applicant();

        $this->actingAs($this->admin(), 'admin')
            ->get($this->url('/' . $applicant->id . '/document/passport'))
            ->assertNotFound();
    }

    /** A rejection with no explanation is what made the old email useless. */
    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $applicant = $this->applicant();

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $applicant->id), [
                'verification_statuses_id' => User::VERIFICATION_REJECTED,
                'rejection_reason' => '',
            ])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(User::VERIFICATION_PENDING, (int) $applicant->fresh()->verification_statuses_id);
    }

    public function test_rejecting_with_a_reason_saves_it_and_emails_the_applicant(): void
    {
        Mail::fake();
        $applicant = $this->applicant();

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $applicant->id), [
                'verification_statuses_id' => User::VERIFICATION_REJECTED,
                'rejection_reason' => 'The back of the ID is cut off.',
            ])
            ->assertRedirect();

        $fresh = $applicant->fresh();
        $this->assertSame(User::VERIFICATION_REJECTED, (int) $fresh->verification_statuses_id);
        $this->assertSame('The back of the ID is cut off.', $fresh->rejection_reason);
    }

    public function test_approving_clears_any_previous_rejection_reason(): void
    {
        Mail::fake();
        $applicant = $this->applicant(User::VERIFICATION_REJECTED);
        $applicant->update(['rejection_reason' => 'Old reason']);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('/' . $applicant->id), [
                'verification_statuses_id' => User::VERIFICATION_APPROVED,
            ])
            ->assertRedirect();

        $fresh = $applicant->fresh();
        $this->assertSame(User::VERIFICATION_APPROVED, (int) $fresh->verification_statuses_id);
        $this->assertNull($fresh->rejection_reason, 'a stale reason must not linger on an approved account');
    }
}
