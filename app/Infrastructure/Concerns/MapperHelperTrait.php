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
     * @param EnumLogContext  $logContext Entity + column identity for structured logging
     *
     * @return T The parsed enum or fallback
     */
    protected static function buildEnum(
        string $enumClass,
        string|int $value,
        BackedEnum $fallback,
        EnumLogContext $logContext,
    ): BackedEnum {
        /** @var T|null $result */
        $result = $enumClass::tryFrom($value);

        if ($result === null) {
            Log::error("Unknown {$enumClass} in database - possible API change", [
                'external_id' => $logContext->externalId,
                $logContext->fieldName => $value,
            ]);

            return $fallback;
        }

        return $result;
    }

    /**
     * Nullable sibling of buildEnum(): null reads as null, and an unknown value logs and reads as null.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T|null
     */
    protected static function buildNullableEnum(
        string $enumClass,
        string|int|null $value,
        EnumLogContext $logContext,
    ): ?BackedEnum {
        if ($value === null) {
            return null;
        }

        /** @var T|null $result */
        $result = $enumClass::tryFrom($value);

        if ($result === null) {
            Log::error("Unknown {$enumClass} in database - possible API change", [
                'external_id' => $logContext->externalId,
                $logContext->fieldName => $value,
            ]);
        }

        return $result;
    }
}
