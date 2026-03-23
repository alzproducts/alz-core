<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Carbon\CarbonImmutable;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Shared date parsing for Linnworks API responses.
 *
 * Handles null/empty strings, the Linnworks sentinel value (0001-01-01T00:00:00),
 * and throws for genuinely malformed dates (API contract violations).
 */
final class LinnworksDateParser
{
    /**
     * @throws InvalidApiResponseException When date string is malformed
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0001-01-01T00:00:00') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateTimeImmutable();
        } catch (DateMalformedStringException $e) {
            Log::critical('Linnworks API returned malformed date', [
                'value' => $value,
            ]);

            throw new InvalidApiResponseException(
                'Linnworks',
                "Failed to parse Linnworks date: {$value}",
                $e,
            );
        }
    }
}
