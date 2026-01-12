<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

use BackedEnum;
use Illuminate\Support\Facades\Log;

/**
 * Helper methods for Infrastructure mappers.
 *
 * Provides common functionality for converting database values to Domain enums
 * with logging for unknown values.
 */
trait MapperHelperTrait
{
    /**
     * Build a BackedEnum from a database value with logging for unknown values.
     *
     * When the database contains a value that doesn't match any enum case,
     * logs an error (indicating possible API/schema change) and returns the fallback.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass  The enum class to instantiate
     * @param string|int      $value      The raw value from database
     * @param T               $fallback   Default value when parsing fails
     * @param int             $externalId Entity's external ID for logging context
     * @param string          $fieldName  Field name for logging context
     *
     * @return T The parsed enum or fallback
     */
    protected static function buildEnum(
        string $enumClass,
        string|int $value,
        BackedEnum $fallback,
        int $externalId,
        string $fieldName,
    ): BackedEnum {
        /** @var T|null $result */
        $result = $enumClass::tryFrom($value);

        if ($result === null) {
            Log::error("Unknown {$enumClass} in database - possible API change", [
                'external_id' => $externalId,
                $fieldName => $value,
            ]);

            return $fallback;
        }

        return $result;
    }
}
