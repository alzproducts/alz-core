<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\CallTracking\ReconcileCallAttributionCollisionsJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class CallTrackingScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        Schedule::job(new ReconcileCallAttributionCollisionsJob())
            ->name('detect-call-attribution-collisions')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(5);
    }
}
