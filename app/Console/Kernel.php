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
        // Keep the Yassen catalog & prices in sync, and resolve pending supplier
        // orders. Both no-op unless YASSEN_SYNC_ENABLED=true with a token set.
        // Requires the system cron entry: * * * * * php artisan schedule:run
        $schedule->command('yassen:sync')->hourly()->withoutOverlapping();
        $schedule->command('yassen:check-orders')->everyFiveMinutes()->withoutOverlapping();
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
