<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Dispatchers;

use App\Application\Contracts\Mixpanel\MixpanelSyncDispatcherInterface;
use App\Application\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use Override;

/**
 * Queue-backed dispatcher for Mixpanel synchronisation.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedMixpanelSyncDispatcher implements MixpanelSyncDispatcherInterface
{
    #[Override]
    public function dispatchCampaignLookupSync(): void
    {
        SyncCampaignLookupTableJob::dispatch();
    }
}
