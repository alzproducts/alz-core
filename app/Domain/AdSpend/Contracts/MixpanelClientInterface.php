<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Contracts;

use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\AdSpendEvent;

interface MixpanelClientInterface
{
    /**
     * Import batch of ad spend events to Mixpanel.
     *
     * @param array<int, AdSpendEvent> $events
     *
     * @throws MixpanelApiException
     * @throws ApiRateLimitException
     */
    public function importBatch(array $events): void;
}
