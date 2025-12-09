<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class SyncAdSpendCommand extends Command
{
    protected $signature = 'adspend:sync {--date= : Date in YYYY-MM-DD format (default: yesterday)}';

    protected $description = 'Manually sync Google Ads data to Mixpanel for a specific date';

    /**
     * @throws DateMalformedStringException
     */
    public function handle(): int
    {
        $dateOption = $this->option('date');

        // Type narrowing: option() returns array|bool|string|null
        $dateString = \is_string($dateOption) ? $dateOption : null;

        // Validate date format if provided
        if (($dateString !== null) && (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString) !== 1)) {
            $this->error('Date must be in YYYY-MM-DD format');
            return self::FAILURE;
        }

        // Convert to DateTimeImmutable for type safety, or null to use job's default (yesterday)
        $date = ($dateString !== null) ? new DateTimeImmutable($dateString) : null;

        $displayDate = $date?->format('Y-m-d') ?? 'yesterday';
        $this->info("Dispatching ad spend sync for {$displayDate}...");

        SyncGoogleAdsToMixpanelJob::dispatch($date);

        $this->info('Job dispatched successfully. Monitor progress in Horizon dashboard.');

        return self::SUCCESS;
    }
}
