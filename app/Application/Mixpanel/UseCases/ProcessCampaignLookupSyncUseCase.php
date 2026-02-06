<?php

declare(strict_types=1);

namespace App\Application\Mixpanel\UseCases;

use App\Application\Jobs\Mixpanel\SyncCampaignLookupTableJob;

/**
 * Process campaign lookup table synchronization.
 *
 * Queues the campaign lookup sync for async processing.
 */
final readonly class ProcessCampaignLookupSyncUseCase
{
    public function execute(): void
    {
        SyncCampaignLookupTableJob::dispatch();
    }
}
