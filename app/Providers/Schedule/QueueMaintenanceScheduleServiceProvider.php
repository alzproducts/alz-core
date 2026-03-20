<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Queue Maintenance Schedule Definitions
 *
 * Schedules periodic queue housekeeping tasks:
 * - Horizon metrics snapshots (required for dashboard graphs)
 * - Failed job pruning (prevents unbounded table growth)
 */
final class QueueMaintenanceScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Horizon metrics: captures queue depth, throughput, and wait times
        // Without this, the Horizon dashboard metrics graphs remain empty
        Schedule::command('horizon:snapshot')
            ->everyFiveMinutes()
            ->onOneServer();

        // Prune failed jobs older than 7 days to prevent table bloat
        // Retains enough history for debugging while keeping the table manageable
        Schedule::command('queue:prune-failed --hours=168')
            ->daily()
            ->onOneServer();
    }
}
