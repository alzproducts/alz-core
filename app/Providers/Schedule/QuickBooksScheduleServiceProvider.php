<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\QuickBooks\UpdateQuickBooksConnectionTokenJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Weekly refresh keeps the 100-day rolling refresh-token expiry from lapsing on an idle connection.
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
    }
}
