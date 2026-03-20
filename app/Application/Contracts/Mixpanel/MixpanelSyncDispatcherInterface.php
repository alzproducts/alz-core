<?php

declare(strict_types=1);

namespace App\Application\Contracts\Mixpanel;

/**
 * Dispatch Mixpanel synchronisation tasks.
 *
 * Application layer uses this to trigger async sync without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface MixpanelSyncDispatcherInterface
{
    public function dispatchCampaignLookupSync(): void;
}
