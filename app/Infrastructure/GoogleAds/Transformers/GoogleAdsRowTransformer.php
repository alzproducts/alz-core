<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Transformers;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\GoogleAds\Exceptions\InvalidGoogleAdsResponseException;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\Segments;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
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
     * Transform a Google Ads API row into a domain CampaignMetrics object.
     *
     * @throws InvalidGoogleAdsResponseException When required fields are missing or invalid
     */
    public static function toCampaignMetrics(GoogleAdsRow $row): CampaignMetrics
    {
        [$campaign, $metrics, $segments] = self::validateNestedObjects($row);

        return new CampaignMetrics(
            campaignId: self::validateIntField($campaign->getId(), 'campaign.id'),
            campaignName: self::validateStringField($campaign->getName(), 'campaign.name'),
            date: self::validateStringField($segments->getDate(), 'segments.date'),
            costInPounds: self::validateIntField($metrics->getCostMicros(), 'metrics.cost_micros') / 1_000_000,
            clicks: self::validateIntField($metrics->getClicks(), 'metrics.clicks'),
            impressions: self::validateIntField($metrics->getImpressions(), 'metrics.impressions'),
            conversions: self::validateFloatField($metrics->getConversions(), 'metrics.conversions'),
        );
    }

    /**
     * Validate and extract nested objects from the row.
     *
     * @return array{0: Campaign, 1: Metrics, 2: Segments}
     *
     * @throws InvalidGoogleAdsResponseException When campaign, metrics, or segments are missing
     */
    private static function validateNestedObjects(GoogleAdsRow $row): array
    {
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

        return [$campaign, $metrics, $segments];
    }

    /**
     * Validate a string field from the API response.
     *
     * @throws InvalidGoogleAdsResponseException When value is null or not a string
     */
    private static function validateStringField(mixed $value, string $field): string
    {
        if ($value === null) {
            throw InvalidGoogleAdsResponseException::missingField($field, $field);
        }

        if (!\is_string($value)) {
            throw InvalidGoogleAdsResponseException::invalidValue($field, 'Expected string, got ' . \get_debug_type($value));
        }

        return $value;
    }

    /**
     * Validate an integer field from the API response.
     *
     * Google Ads API may return integers as strings (protobuf int64 encoding).
     *
     * @throws InvalidGoogleAdsResponseException When value is null or not int|string
     */
    private static function validateIntField(mixed $value, string $field): int
    {
        if ($value === null) {
            throw InvalidGoogleAdsResponseException::missingField($field, $field);
        }

        if (!\is_int($value) && !\is_string($value)) {
            throw InvalidGoogleAdsResponseException::invalidValue($field, 'Expected int|string, got ' . \get_debug_type($value));
        }

        return (int) $value;
    }

    /**
     * Validate a float field from the API response.
     *
     * Google Ads API may return floats as strings (protobuf encoding).
     *
     * @throws InvalidGoogleAdsResponseException When value is null or not float|string
     */
    private static function validateFloatField(mixed $value, string $field): float
    {
        if ($value === null) {
            throw InvalidGoogleAdsResponseException::missingField($field, $field);
        }

        if (!\is_float($value) && !\is_string($value)) {
            throw InvalidGoogleAdsResponseException::invalidValue($field, 'Expected float|string, got ' . \get_debug_type($value));
        }

        return (float) $value;
    }
}
