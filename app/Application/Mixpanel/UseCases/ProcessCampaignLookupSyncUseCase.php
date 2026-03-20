<?php

declare(strict_types=1);

namespace App\Application\Mixpanel\UseCases;

use App\Application\Contracts\Mixpanel\MixpanelSyncDispatcherInterface;

/**
 * Process campaign lookup table synchronization.
 *
 * Queues the campaign lookup sync for async processing.
 */
final readonly class ProcessCampaignLookupSyncUseCase
{
    public function __construct(
        private MixpanelSyncDispatcherInterface $dispatcher,
    ) {}

    public function execute(): void
    {
        $this->dispatcher->dispatchCampaignLookupSync();
    }
}
