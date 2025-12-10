<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds\Exceptions;

use App\Infrastructure\Exceptions\ApiException;

/**
 * Thrown when Bing Ads API response contains invalid or unexpected data.
 *
 * This is a runtime validation exception (always active in production),
 * not an assertion (which compiles-out).
 *
 * Distinguishes from ExternalServiceUnavailableException:
 * - ExternalServiceUnavailableException: API error/unavailable (rate limit, network)
 * - InvalidBingAdsResponseException: API returned data but format is invalid
 */
final class InvalidBingAdsResponseException extends ApiException
{
    public static function missingColumn(string $column): self
    {
        return new self("Bing Ads CSV missing required column: {$column}");
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self("Bing Ads CSV has invalid value for {$field}: {$reason}");
    }

    public static function malformedCsv(string $reason): self
    {
        return new self("Bing Ads CSV is malformed: {$reason}");
    }
}
