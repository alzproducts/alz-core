<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds\Fakes;

use App\Application\Contracts\BingAdsClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedBingAdsClient implements BingAdsClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function getSource(): AdSource
    {
        return AdSource::Bing;
    }

    public function getCampaignMetricsByDateRange(DateRange $range): array
    {
        throw $this->gate->failClosed(LiveIntegration::BingAds);
    }

    public function verifyConnectivity(): void
    {
        throw $this->gate->failClosed(LiveIntegration::BingAds);
    }
}
