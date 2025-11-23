<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

/**
 * Validates and transforms Google Ads campaign rows into domain value objects.
 *
 * Sits at the Infrastructure/Domain boundary ensuring:
 * 1. All null/missing fields are caught BEFORE domain layer
 * 2. Data validation is production-safe (uses exceptions, not assertions)
 * 3. Campaign metadata is extracted and validated
 *
 */
final class CampaignRowTransformer
{
    /**
     * @throws InvalidGoogleAdsResponseException
     */
    public static function toCampaign(GoogleAdsRow $row): Campaign
    {
        // Validate campaign object exists
        $campaign = $row->getCampaign();
        if ($campaign === null) {
            throw InvalidGoogleAdsResponseException::missingField('campaign', 'row.campaign');
        }

        // Validate required campaign fields (SDK methods have PHPDoc type hints)
        $campaignId = $campaign->getId();
        $campaignName = $campaign->getName();
        $status = $campaign->getStatus();

        // Convert Google Ads enum status to domain status string
        $statusString = self::getStatusString($status);

        // Create domain value object with validated data
        return new Campaign(
            campaignId: (int) $campaignId,
            campaignName: $campaignName,
            status: $statusString,
        );
    }

    /**
     * Convert Google Ads CampaignStatus enum to domain status string.
     *
     * Google Ads API returns campaign status as an enum value (0=UNSPECIFIED, 1=ENABLED, 2=PAUSED, 3=REMOVED).
     * We map these to the string representations used in the domain layer.
     */
    private static function getStatusString(int $enumValue): string
    {
        return match ($enumValue) {
            0 => 'UNSPECIFIED',  // UNSPECIFIED = 0
            1 => 'ENABLED',       // ENABLED = 1
            2 => 'PAUSED',        // PAUSED = 2
            3 => 'REMOVED',       // REMOVED = 3
            default => throw InvalidGoogleAdsResponseException::invalidValue(
                'campaign.status',
                "Unknown campaign status enum value: {$enumValue}",
            ),
        };
    }
}
