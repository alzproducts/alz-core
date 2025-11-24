<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

interface GoogleAdsClientInterface
{
    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return array<int, CampaignMetrics>
     *
     * @throws ExternalServiceUnavailableException
     */
    public function getDailyCampaignMetrics(string $date): array;

    /**
     * Fetch all active campaigns.
     *
     * @return array<int, Campaign>
     *
     * @throws ExternalServiceUnavailableException
     */
    public function getCampaigns(): array;
}
