<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds\Exceptions;

use App\Infrastructure\Exceptions\ApiException;
use Override;

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
    public function __construct(
        public readonly string $field,
        public readonly string $detail,
    ) {
        parent::__construct('Invalid Bing Ads API response');
    }

    public static function missingColumn(string $column): self
    {
        return new self($column, 'missing required column');
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self($field, $reason);
    }

    public static function malformedCsv(string $reason): self
    {
        return new self('', $reason);
    }

    #[Override]
    public function context(): array
    {
        return \array_filter([
            'field' => $this->field,
            'detail' => $this->detail,
        ], static fn(string $value): bool => $value !== '');
    }
}
