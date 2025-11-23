<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Console\Command;

final class SyncAdSpendCommand extends Command
{
    protected $signature = 'adspend:sync {--date= : Date in YYYY-MM-DD format (default: yesterday)}';

    protected $description = 'Manually sync Google Ads data to Mixpanel for a specific date';

    public function handle(): int
    {
        $date = $this->option('date');

        // Validate date format if provided
        if (($date !== null) && (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
            $this->error('Date must be in YYYY-MM-DD format');
            return self::FAILURE;
        }

        $syncDate = $date ?? \now()->subDay()->format('Y-m-d');

        $this->info("Dispatching ad spend sync for {$syncDate}...");

        SyncGoogleAdsToMixpanelJob::dispatch($syncDate);

        $this->info('Job dispatched successfully. Monitor progress in Horizon dashboard.');

        return self::SUCCESS;
    }
}
