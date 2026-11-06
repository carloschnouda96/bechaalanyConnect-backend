<?php

namespace Tests\Feature\Cms;

use App\Models\User;
use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsLog;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Regressions from security audit run-4: CMS authorization, uploads, KYC, dashboard. */
class CmsHardeningTest extends TestCase
{
    private function admin(?int $roleId = null): Admin
    {
        $admin = new Admin();
        $admin->name = 'Harden Admin';
        $admin->email = 'harden_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = $roleId;
        $admin->save();

        return $admin->refresh();
    }

    /** @param array<string, string[]> $grants route => rights */
    private function role(array $grants): int
    {
        $role = new AdminRole();
        $role->title = 'Role ' . uniqid();
        $role->save();

        foreach ($grants as $route => $rights) {
            $permission = new AdminRolePermission();
            $permission->admin_role_id = $role->id;
            $permission->cms_page_id = CmsPage::where('route', $route)->firstOrFail()->id;
            foreach (['browse', 'read', 'edit', 'add', 'delete'] as $right) {
                $permission->{$right} = in_array($right, $rights, true) ? 1 : 0;
            }
            $permission->save();
        }

        return $role->id;
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    private function customer(array $overrides = []): User
    {
        return User::create($overrides + [
            'username' => 'harden_' . uniqid(),
            'email' => 'harden_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_PENDING,
        ]);
    }

    // --- Path classification ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedPaths')]
    public function test_an_encoded_path_is_refused(string $path): void
    {
        $user = $this->customer();
        $admin = $this->admin($this->role(['users' => ['read']]));

        $this->actingAs($admin, 'admin')->get($this->url(str_replace('{id}', (string) $user->id, $path)))->assertStatus(400);
    }

    public static function encodedPaths(): array
    {
        return [
            'edit' => ['users/{id}/%65dit'],
            'slash' => ['users/{id}%2Fedit'],
            'create' => ['users/%63reate'],
            'order' => ['users/%6Frder'],
        ];
    }

    public function test_plain_paths_are_unchanged(): void
    {
        $user = $this->customer();
        $admin = $this->admin($this->role(['users' => ['read']]));

        $this->actingAs($admin, 'admin')->get($this->url("users/{$user->id}"))->assertOk();
        $this->actingAs($admin, 'admin')->get($this->url("users/{$user->id}/edit"))->assertForbidden();
    }

    // --- Route-cache independence -----------------------------------------------------

    public function test_patch_is_refused_by_the_middleware_itself(): void
    {
        // A route with no app override, the state a route cache leaves the vendor's in.
        Route::middleware(['web', 'admin'])->patch($this->url('users/patch-probe'), fn () => 'reached');
        $admin = $this->admin($this->role(['users' => ['browse', 'read']]));

        $this->actingAs($admin, 'admin')->patch($this->url('users/patch-probe'))->assertForbidden();
    }

    public function test_the_middleware_protects_a_super_admin_on_any_controller(): void
    {
        $super = $this->admin();
        Route::middleware(['web', 'admin'])->put($this->url('admins/{admin}/probe'), fn () => 'reached');
        $admin = $this->admin($this->role(['admins' => ['browse', 'read', 'edit', 'delete']]));

        $this->actingAs($admin, 'admin')->put($this->url("admins/{$super->id}/probe"))->assertForbidden();
    }

    // --- Audit log --------------------------------------------------------------------

    public function test_a_body_id_cannot_change_the_logged_record(): void
    {
        $user = $this->customer();
        $admin = $this->admin();
        // The log only applies to role admins.
        $role = $this->admin($this->role(['users' => ['browse', 'read', 'edit']]));

        $this->actingAs($role, 'admin')->put($this->url("users/{$user->id}"), [
            'id' => 321,
            'username' => $user->username,
            'email' => $user->email,
            'verification_statuses_id' => $user->verification_statuses_id,
        ]);

        $log = CmsLog::where('admin_id', $role->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $user->id, (string) $log->record_id);
    }

    public function test_a_refused_action_writes_no_log_row(): void
    {
        $user = $this->customer();
        $role = $this->admin($this->role(['users' => ['browse', 'read']]));

        $this->actingAs($role, 'admin')->put($this->url("users/{$user->id}"), ['username' => 'x'])->assertForbidden();

        $this->assertSame(0, CmsLog::where('admin_id', $role->id)->count());
    }

    // --- Vendor API -------------------------------------------------------------------

    public function test_the_vendor_api_cannot_be_turned_on_for_a_page(): void
    {
        $page = CmsPage::where('route', 'statuses')->firstOrFail();
        DB::table('cms_pages')->where('id', $page->id)->update(['apis' => 1]);
        Route::middleware('api')->post('api-probe/statuses', 'Hellotreedigital\Cms\Controllers\ApisController@index')->defaults('route', 'statuses');

        $this->postJson('/api-probe/statuses', ['custom_validation' => [['where', ['id', '>', 0]]]])->assertNotFound();
    }

    // --- Uploads ----------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousUploads')]
    public function test_active_content_cannot_be_uploaded(string $name, string $body, string $mime): void
    {
        Storage::fake('public');
        $page = 'users';
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post($this->url("{$page}/edit/images"), [
            'images' => [UploadedFile::fake()->createWithContent($name, $body)->mimeType($mime)],
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function dangerousUploads(): array
    {
        return [
            'html' => ['x.html', '<html><script>alert(1)</script></html>', 'text/html'],
            'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            'html named png' => ['x.png', '<!doctype html><script>alert(1)</script>', 'image/png'],
            'php' => ['x.php', '<?php echo 1;', 'application/x-php'],
        ];
    }

    public function test_an_ordinary_image_can_still_be_uploaded(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin(), 'admin')->post($this->url('users/edit/images'), [
            'images' => [UploadedFile::fake()->image('ok.png', 10, 10)],
        ]);

        $this->assertNotSame(422, $response->status());
    }

    public function test_the_ckeditor_callback_number_must_be_a_number(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post($this->url('ckeditor/images') . '?CKEditorFuncNum=1);alert(1);//', [
                'upload' => UploadedFile::fake()->image('ok.png', 10, 10),
            ])->assertStatus(422);
    }

    // --- KYC --------------------------------------------------------------------------

    public function test_a_kyc_decision_is_refused_if_the_documents_changed(): void
    {
        Mail::fake();
        $user = $this->customer(['id_front_image' => 'kyc/old.png']);
        $oldFingerprint = \App\Http\Controllers\Cms\KycController::fingerprint($user);

        $user->forceFill(['id_front_image' => 'kyc/new.png'])->save();

        $this->actingAs($this->admin(), 'admin')->put($this->url("kyc-queue/{$user->id}"), [
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
            'reviewed_fingerprint' => $oldFingerprint,
        ])->assertSessionHasErrors('reviewed_fingerprint');

        $this->assertSame(User::VERIFICATION_PENDING, (int) $user->fresh()->verification_statuses_id);
    }

    public function test_a_kyc_decision_applies_to_the_documents_reviewed(): void
    {
        Mail::fake();
        $user = $this->customer(['id_front_image' => 'kyc/old.png']);

        $this->actingAs($this->admin(), 'admin')->put($this->url("kyc-queue/{$user->id}"), [
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
            'reviewed_fingerprint' => \App\Http\Controllers\Cms\KycController::fingerprint($user),
        ])->assertSessionHasNoErrors();

        $this->assertSame(User::VERIFICATION_APPROVED, (int) $user->fresh()->verification_statuses_id);
    }

    public function test_a_kyc_decision_needs_the_fingerprint(): void
    {
        $user = $this->customer();

        $this->actingAs($this->admin(), 'admin')->put($this->url("kyc-queue/{$user->id}"), [
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ])->assertSessionHasErrors('reviewed_fingerprint');
    }

    public function test_the_review_form_does_not_preselect_approve(): void
    {
        $user = $this->customer();

        $this->actingAs($this->admin(), 'admin')->get($this->url("kyc-queue/{$user->id}"))
            ->assertOk()
            ->assertSee('name="reviewed_fingerprint"', false)
            ->assertSee('value="" selected disabled', false);
    }

    // --- Headers ---

    public function test_cms_pages_cannot_be_framed_by_another_site(): void
    {
        $this->get($this->url('login'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // --- Dashboard --------------------------------------------------------------------

    public function test_a_role_with_no_grants_sees_no_figures_on_the_dashboard(): void
    {
        $admin = $this->admin($this->role([]));

        $this->actingAs($admin, 'admin')->get($this->url('home'))
            ->assertOk()
            ->assertDontSee('Needs attention')
            ->assertDontSee('Sales')
            ->assertDontSee('System health');
    }

    public function test_a_super_admin_sees_the_whole_dashboard(): void
    {
        $this->actingAs($this->admin(), 'admin')->get($this->url('home'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Sales')
            ->assertSee('System health');
    }
}
