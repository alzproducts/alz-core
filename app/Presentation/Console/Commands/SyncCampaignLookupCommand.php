<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Presentation\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use Illuminate\Console\Command;

final class SyncCampaignLookupCommand extends Command
{
    protected $signature = 'adspend:sync-lookup';

    protected $description = 'Manually sync campaign lookup table to Mixpanel';

    public function handle(): int
    {
        $this->info('Dispatching campaign lookup table sync...');

        SyncCampaignLookupTableJob::dispatch();

        $this->info('Job dispatched successfully. Monitor progress in Horizon dashboard.');

        return self::SUCCESS;
    }
}
