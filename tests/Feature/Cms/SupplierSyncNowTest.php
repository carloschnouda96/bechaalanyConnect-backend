<?php

namespace Tests\Feature\Cms;

use App\Console\Commands\SyncSupplierCatalogCommand;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Sync now" on Supplier health / Supplier imports, and the per-supplier lock that
 * stops it (or the HTTP cron) from running one catalog twice at once.
 */
class SupplierSyncNowTest extends TestCase
{
    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Sync Admin';
        $admin->email = 'sync_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function url(string $key): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/supplier-health/sync/' . $key;
    }

    private function enableSwift(): void
    {
        config([
            'services.swift.enabled' => true,
            'services.swift.key' => 'test-key',
            'services.swift.base_url' => 'https://swift.example.test',
        ]);
    }

    public function test_an_unknown_supplier_is_refused(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('nope'))
            ->assertRedirect()
            ->assertSessionHasErrors('sync');
    }

    public function test_a_disabled_supplier_is_refused(): void
    {
        config(['services.swift.enabled' => false]);

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('swift'))
            ->assertSessionHasErrors('sync');
    }

    public function test_an_enabled_supplier_runs_its_sync_command(): void
    {
        $this->enableSwift();

        Artisan::shouldReceive('call')->once()->with('swift:sync')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Syncing swift catalog & prices…');

        $this->actingAs($this->admin(), 'admin')
            ->put($this->url('swift'))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('supplier_sync_output', [
                'key' => 'swift',
                'ok' => true,
                'text' => 'Syncing swift catalog & prices…',
            ]);
    }

    public function test_a_second_run_of_the_same_supplier_is_skipped_while_one_holds_the_lock(): void
    {
        $this->enableSwift();
        Http::fake();

        $lock = Cache::lock(SyncSupplierCatalogCommand::lockName('swift'), 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('swift:sync')
                ->expectsOutputToContain('already running')
                ->assertExitCode(0);

            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_the_buttons_render_on_both_pages(): void
    {
        $this->enableSwift();
        Http::fake(); // Supplier health asks every enabled connector for its balance.
        $admin = $this->admin();
        $prefix = '/' . config('hellotree.cms_route_prefix');

        $this->actingAs($admin, 'admin')->get($prefix . '/supplier-health')
            ->assertOk()
            ->assertSee('Sync swift now');

        $this->actingAs($admin, 'admin')->get($prefix . '/supplier-categories')
            ->assertOk()
            ->assertSee('How importing works')
            ->assertSee('Sync swift now');
    }
}
