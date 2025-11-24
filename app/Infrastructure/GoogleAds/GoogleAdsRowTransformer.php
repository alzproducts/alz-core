<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

/**
 * Validates and transforms Google Ads API responses into domain value objects.
 *
 * Critical Role: This class sits at the Infrastructure/Domain boundary and ensures:
 * 1. All null/missing fields are caught BEFORE domain layer
 * 2. Data validation is production-safe (uses exceptions, not assertions)
 * 3. Type conversions (micros → pounds) are explicit and validated
 *
 * Design Pattern: Transformer converts external data with runtime validation.
 *
 * Exception Strategy:
 * - Assertions in Domain layer compile-out in production
 * - Validation in Infrastructure layer always runs in production
 * - This ensures external data safety at all times
 */
final class GoogleAdsRowTransformer
{
    /**
     * @throws InvalidGoogleAdsResponseException
     */
    public static function toCampaignMetrics(GoogleAdsRow $row): CampaignMetrics
    {
        // Validate nested objects exist
        $campaign = $row->getCampaign();
        if ($campaign === null) {
            throw InvalidGoogleAdsResponseException::missingField('campaign', 'row.campaign');
        }

        $metrics = $row->getMetrics();
        if ($metrics === null) {
            throw InvalidGoogleAdsResponseException::missingField('metrics', 'row.metrics');
        }

        $segments = $row->getSegments();
        if ($segments === null) {
            throw InvalidGoogleAdsResponseException::missingField('segments', 'row.segments');
        }

        // Validate required fields with type checking at boundary
        $campaignId = $campaign->getId();
        if ($campaignId === null) {
            throw InvalidGoogleAdsResponseException::missingField('id', 'campaign.id');
        }
        if (!\is_int($campaignId) && !\is_string($campaignId)) {
            throw InvalidGoogleAdsResponseException::invalidValue('campaign.id', 'Expected int|string, got ' . \get_debug_type($campaignId));
        }

        $campaignName = $campaign->getName();
        if ($campaignName === null) {
            throw InvalidGoogleAdsResponseException::missingField('name', 'campaign.name');
        }
        if (!\is_string($campaignName)) {
            throw InvalidGoogleAdsResponseException::invalidValue('campaign.name', 'Expected string, got ' . \get_debug_type($campaignName));
        }

        $date = $segments->getDate();
        if ($date === null) {
            throw InvalidGoogleAdsResponseException::missingField('date', 'segments.date');
        }
        if (!\is_string($date)) {
            throw InvalidGoogleAdsResponseException::invalidValue('segments.date', 'Expected string, got ' . \get_debug_type($date));
        }

        $costMicros = $metrics->getCostMicros();
        if ($costMicros === null) {
            throw InvalidGoogleAdsResponseException::missingField('cost_micros', 'metrics.cost_micros');
        }
        if (!\is_int($costMicros) && !\is_string($costMicros)) {
            throw InvalidGoogleAdsResponseException::invalidValue('metrics.cost_micros', 'Expected int|string, got ' . \get_debug_type($costMicros));
        }

        $clicks = $metrics->getClicks();
        if ($clicks === null) {
            throw InvalidGoogleAdsResponseException::missingField('clicks', 'metrics.clicks');
        }
        if (!\is_int($clicks) && !\is_string($clicks)) {
            throw InvalidGoogleAdsResponseException::invalidValue('metrics.clicks', 'Expected int|string, got ' . \get_debug_type($clicks));
        }

        $impressions = $metrics->getImpressions();
        if ($impressions === null) {
            throw InvalidGoogleAdsResponseException::missingField('impressions', 'metrics.impressions');
        }
        if (!\is_int($impressions) && !\is_string($impressions)) {
            throw InvalidGoogleAdsResponseException::invalidValue('metrics.impressions', 'Expected int|string, got ' . \get_debug_type($impressions));
        }

        $conversions = $metrics->getConversions();
        if ($conversions === null) {
            throw InvalidGoogleAdsResponseException::missingField('conversions', 'metrics.conversions');
        }
        if (!\is_float($conversions) && !\is_string($conversions)) {
            throw InvalidGoogleAdsResponseException::invalidValue('metrics.conversions', 'Expected float|string, got ' . \get_debug_type($conversions));
        }

        // Create domain value object with validated data
        return new CampaignMetrics(
            campaignId: (int) $campaignId,
            campaignName: $campaignName,
            date: $date,
            costInPounds: (float) ($costMicros / 1_000_000),
            clicks: (int) $clicks,
            impressions: (int) $impressions,
            conversions: (float) $conversions,
        );
    }
}
