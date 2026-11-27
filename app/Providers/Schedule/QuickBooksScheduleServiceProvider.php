<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderCatchUpJob;
use App\Infrastructure\Jobs\QuickBooks\UpdateQuickBooksConnectionTokenJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Weekly refresh keeps the 100-day rolling refresh-token expiry from lapsing on an idle connection.
 *
 * The catch-up job is a class string so each hour gets its own run id; it has no `withoutOverlapping()`, which would
 * cover only the seconds-long dispatch, while the job's uniqueness and its open-run check keep runs apart.
 */
final class QuickBooksScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        Schedule::job(new UpdateQuickBooksConnectionTokenJob())
            ->name('quickbooks-token-refresh')
            ->weeklyOn(1, '04:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(15);

        Schedule::job(SyncQuickBooksOrderCatchUpJob::class)
            ->name('quickbooks-order-catch-up')
            ->hourly()
            ->onOneServer();
    }
}
