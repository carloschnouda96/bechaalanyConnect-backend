<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Keep each supplier's catalog & prices in sync, and resolve pending
        // supplier orders. Every command no-ops unless that supplier's
        // *_SYNC_ENABLED=true with credentials set.
        // Requires the system cron entry: * * * * * php artisan schedule:run
        $schedule->command('yassen:sync')->hourly()->withoutOverlapping();
        $schedule->command('yassen:check-orders')->everyFiveMinutes()->withoutOverlapping();

        $schedule->command('swift:sync')->hourly()->withoutOverlapping();
        $schedule->command('swift:check-orders')->everyFiveMinutes()->withoutOverlapping();

        $schedule->command('1xpanel:sync')->hourly()->withoutOverlapping();
        $schedule->command('1xpanel:check-orders')->everyFiveMinutes()->withoutOverlapping();

        // usharez exposes no order-status endpoint, so check-orders is a no-op
        // report; it stays scheduled for parity with the other suppliers.
        $schedule->command('usharez:sync')->hourly()->withoutOverlapping();
        $schedule->command('usharez:check-orders')->everyFiveMinutes()->withoutOverlapping();

        // U-Manage caps at 1000 requests/hour per key, hence check-orders' lower
        // default --limit (see CheckUmanageOrders).
        $schedule->command('umanage:sync')->hourly()->withoutOverlapping();
        $schedule->command('umanage:check-orders')->everyFiveMinutes()->withoutOverlapping();

        // Bycel has no order-status endpoint: check-orders re-drives PIN claiming,
        // and reconcile sweeps intents whose outcome was never settled.
        $schedule->command('bycel:sync')->hourly()->withoutOverlapping();
        $schedule->command('bycel:check-orders')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('bycel:reconcile --auto-fail')->everyFifteenMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
