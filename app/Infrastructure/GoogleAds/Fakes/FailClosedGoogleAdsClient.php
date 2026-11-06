<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Fakes;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedGoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function getSource(): AdSource
    {
        return AdSource::Google;
    }

    public function getCampaignMetricsByDateRange(DateRange $range): array
    {
        throw $this->gate->failClosed(LiveIntegration::GoogleAds);
    }

    public function verifyConnectivity(): void
    {
        throw $this->gate->failClosed(LiveIntegration::GoogleAds);
    }

    public function getCampaigns(): array
    {
        throw $this->gate->failClosed(LiveIntegration::GoogleAds);
    }
}
