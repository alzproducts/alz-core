<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Fakes;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use DateTimeImmutable;

final readonly class FailClosedMixpanelClient implements MixpanelClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function verifyConnectivity(): void
    {
        throw $this->gate->failClosed(LiveIntegration::Mixpanel);
    }

    public function importCampaigns(array $campaigns, AdSource $source): void
    {
        throw $this->gate->failClosed(LiveIntegration::Mixpanel);
    }

    public function replaceLookupTable(string $tableKey, array $headers, array $rows): void
    {
        throw $this->gate->failClosed(LiveIntegration::Mixpanel);
    }

    public function getExistingOrderHashes(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        throw $this->gate->failClosed(LiveIntegration::Mixpanel);
    }

    public function importOrders(array $orders, array $customerTradeMap): void
    {
        throw $this->gate->failClosed(LiveIntegration::Mixpanel);
    }
}
