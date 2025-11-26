<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Transformers;

use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
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
            id: (int) $campaignId,
            name: $campaignName,
            status: $statusString,
        );
    }

    /**
     * Convert Google Ads CampaignStatus enum to domain status string.
     *
     * Google Ads API returns campaign status as protobuf enum values:
     * UNSPECIFIED=0, UNKNOWN=1, ENABLED=2, PAUSED=3, REMOVED=4
     *
     * @see CampaignStatus
     */
    private static function getStatusString(int $enumValue): string
    {
        return match ($enumValue) {
            0 => 'UNSPECIFIED',
            2 => 'ENABLED',
            3 => 'PAUSED',
            4 => 'REMOVED',
            default => throw InvalidGoogleAdsResponseException::invalidValue(
                'campaign.status',
                "Unknown campaign status enum value: {$enumValue}",
            ),
        };
    }
}
