<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Exceptions;

use App\Infrastructure\Exceptions\ApiException;

final class InvalidGoogleAdsResponseException extends ApiException
{
    /**
     * Thrown when Google Ads API response contains null/missing required fields.
     * This is a **runtime validation exception** (always active in production),
     * not an assertion (which compile-out).
     *
     * Distinguishes from ExternalServiceUnavailableException:
     * - ExternalServiceUnavailableException: API error/unavailable (rate limit, network)
     * - InvalidGoogleAdsResponseException: API returned 200 but data invalid
     */
    public function __construct(
        public readonly string $field,
        public readonly string $detail,
    ) {
        parent::__construct('Invalid Google Ads API response');
    }

    public static function missingField(string $field, string $context = ''): self
    {
        return new self($field, 'missing required field' . ($context !== '' ? " ({$context})" : ''));
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self($field, $reason);
    }

    public function context(): array
    {
        return ['field' => $this->field, 'detail' => $this->detail];
    }
}
