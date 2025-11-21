<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Contracts;

use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;

interface GoogleAdsClientInterface
{
    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return array<int, CampaignMetrics>
     *
     * @throws GoogleAdsApiException
     * @throws ApiRateLimitException
     */
    public function getDailyCampaignMetrics(string $date): array;
}
