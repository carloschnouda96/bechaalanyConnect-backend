<?php

namespace Tests\Feature\Cms;

use App\CreditLedgerEntry;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Hellotreedigital\Cms\Models\Admin;
use Hellotreedigital\Cms\Models\AdminRole;
use Hellotreedigital\Cms\Models\AdminRolePermission;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Users form used to carry credits_balance as an ordinary input, so any save wrote
 * back the balance it had loaded: a customer who spent while the form was open got the
 * money back, with no ledger row. A balance now changes only through the ledger.
 */
class UserBalanceTest extends TestCase
{
    private function admin(?int $roleId = null): Admin
    {
        $admin = new Admin();
        $admin->name = 'Balance Admin';
        $admin->email = 'balance_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = $roleId;
        $admin->save();

        return $admin->refresh();
    }

    private function roleWithUsersRights(array $rights): int
    {
        $role = new AdminRole();
        $role->title = 'Role ' . uniqid();
        $role->save();

        $permission = new AdminRolePermission();
        $permission->admin_role_id = $role->id;
        $permission->cms_page_id = CmsPage::where('route', 'users')->firstOrFail()->id;
        foreach (['browse', 'read', 'edit', 'add', 'delete'] as $right) {
            $permission->{$right} = in_array($right, $rights, true) ? 1 : 0;
        }
        $permission->save();

        return $role->id;
    }

    private function customer(float $balance = 0.0): User
    {
        $user = User::create([
            'username' => 'balance_' . uniqid(),
            'email' => 'balance_' . uniqid() . '@example.test',
            'password' => bcrypt('secret-Password1'),
            'email_verified' => 1,
            'credits_balance' => 0,
            'total_purchases' => 0,
            'received_amount' => 0,
            'verification_statuses_id' => User::VERIFICATION_APPROVED,
        ]);

        if ($balance) {
            DB::transaction(fn () => CreditLedger::record(
                User::where('id', $user->id)->lockForUpdate()->first(),
                $balance,
                CreditLedgerEntry::REASON_OPENING_BALANCE,
                null,
                [],
                'test:' . uniqid(),
                CreditLedgerEntry::ACTOR_SYSTEM
            ));
        }

        return $user->fresh();
    }

    private function url(string $path): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/' . $path;
    }

    private function adjust(Admin $admin, User $user, array $overrides = [])
    {
        return $this->actingAs($admin, 'admin')->put($this->url("users/{$user->id}/balance"), $overrides + [
            'amount' => '5.00',
            'reason' => 'Goodwill credit for ticket 12',
            'token' => (string) Str::uuid(),
        ]);
    }

    public function test_the_users_form_has_no_balance_input(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get($this->url('users/' . $this->customer()->id . '/edit'));

        $response->assertOk();
        $this->assertStringNotContainsString('name="credits_balance"', $response->getContent());
    }

    public function test_saving_the_users_form_cannot_change_a_balance(): void
    {
        Mail::fake();
        $user = $this->customer(40);

        $this->actingAs($this->admin(), 'admin')->put($this->url("users/{$user->id}"), [
            'username' => $user->username,
            'email' => $user->email,
            'credits_balance' => 100,
            'verification_statuses_id' => $user->verification_statuses_id,
        ]);

        $this->assertEquals(40.0, (float) $user->fresh()->credits_balance);
    }

    public function test_an_adjustment_moves_the_balance_through_the_ledger_and_names_the_admin(): void
    {
        $admin = $this->admin();
        $user = $this->customer(10);

        $this->adjust($admin, $user, ['amount' => '2.50', 'reason' => 'Goodwill credit'])->assertRedirect();

        $this->assertEquals(12.5, (float) $user->fresh()->credits_balance);

        $entry = CreditLedgerEntry::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame(CreditLedgerEntry::REASON_ADMIN_ADJUSTMENT, $entry->reason);
        $this->assertSame(CreditLedgerEntry::ACTOR_ADMIN, $entry->actor_type);
        $this->assertEquals($admin->id, $entry->actor_id);
        $this->assertSame('Goodwill credit', $entry->meta['reason']);
    }

    public function test_a_resubmitted_form_applies_once(): void
    {
        $admin = $this->admin();
        $user = $this->customer(10);
        $token = (string) Str::uuid();

        $this->adjust($admin, $user, ['token' => $token]);
        $this->adjust($admin, $user, ['token' => $token]);

        $this->assertEquals(15.0, (float) $user->fresh()->credits_balance);
    }

    public function test_an_adjustment_cannot_take_a_balance_below_zero(): void
    {
        $user = $this->customer(10);

        $this->adjust($this->admin(), $user, ['amount' => '-10.01'])->assertSessionHasErrors('amount');

        $this->assertEquals(10.0, (float) $user->fresh()->credits_balance);
    }

    public function test_an_adjustment_needs_a_reason_and_a_nonzero_amount(): void
    {
        $user = $this->customer(10);
        $admin = $this->admin();

        $this->adjust($admin, $user, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->adjust($admin, $user, ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->adjust($admin, $user, ['amount' => '1.005'])->assertSessionHasErrors('amount');

        $this->assertEquals(10.0, (float) $user->fresh()->credits_balance);
    }

    public function test_a_role_needs_the_edit_right_to_adjust(): void
    {
        $user = $this->customer(10);

        $this->adjust($this->admin($this->roleWithUsersRights(['browse', 'read'])), $user)->assertForbidden();
        $this->assertEquals(10.0, (float) $user->fresh()->credits_balance);

        $this->adjust($this->admin($this->roleWithUsersRights(['browse', 'read', 'edit'])), $user)->assertRedirect();
        $this->assertEquals(15.0, (float) $user->fresh()->credits_balance);
    }

    public function test_the_balance_page_shows_the_current_balance_and_history(): void
    {
        $user = $this->customer(10);
        $admin = $this->admin();
        $this->adjust($admin, $user, ['reason' => 'Visible in history']);

        $this->actingAs($admin, 'admin')->get($this->url("users/{$user->id}/balance"))
            ->assertOk()
            ->assertSee('15.00')
            ->assertSee('Visible in history');
    }
}
