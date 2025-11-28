<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use Spatie\LaravelData\Mappers\NameMapper;

/**
 * Maps camelCase PHP property names to PascalCase for Linnworks API.
 *
 * Linnworks (built on .NET) uses PascalCase in JSON responses.
 * This mapper converts our camelCase property names to match:
 *   itemNumber (PHP) → ItemNumber (API)
 *   stockItemId (PHP) → StockItemId (API)
 *
 * @template-pattern Spatie Data Name Mapper
 */
final class PascalCaseMapper implements NameMapper
{
    /**
     * Convert camelCase property name to PascalCase for input mapping.
     */
    public function map(int|string $name): int|string
    {
        if (\is_int($name)) {
            return $name;
        }

        // Convert camelCase to PascalCase by uppercasing first character
        return \ucfirst($name);
    }
}
