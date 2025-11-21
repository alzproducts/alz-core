<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class InvalidGoogleAdsResponseException extends RuntimeException
{
    /**
     * Thrown when Google Ads API response contains null/missing required fields.
     * This is a **runtime validation exception** (always active in production),
     * not an assertion (which compile-out).
     *
     * Distinguishes from GoogleAdsApiException:
     * - GoogleAdsApiException: API returned error status code
     * - InvalidGoogleAdsResponseException: API returned 200 but data invalid
     */
    public static function missingField(string $field, string $context = ''): self
    {
        $message = "Google Ads response missing required field: {$field}";
        if ($context !== '') {
            $message .= " ({$context})";
        }

        return new self($message);
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self("Google Ads response has invalid value for {$field}: {$reason}");
    }
}
