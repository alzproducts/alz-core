<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Throwable;

/**
 * Enum value validation failed.
 *
 * Thrown when a provided value cannot be mapped to a valid enum case.
 * Used for user input that doesn't match expected enum values.
 */
final class InvalidEnumValueException extends AbstractDataException
{
    /**
     * @param class-string $enumClass
     */
    public function __construct(
        public readonly string $enumClass,
        public readonly string $value,
        public readonly string $context,
        ?Throwable $previous = null,
    ) {
        $enumName = \class_basename($enumClass);
        parent::__construct("Invalid {$enumName} value '{$value}': {$context}", 0, $previous);
    }

    /**
     * Create for unknown enum label/name lookup.
     *
     * @param class-string $enumClass
     */
    public static function unknownLabel(string $enumClass, string $label): self
    {
        return new self(
            enumClass: $enumClass,
            value: $label,
            context: 'no matching enum case found',
        );
    }

    /**
     * Create for invalid backing value.
     *
     * @param class-string $enumClass
     */
    public static function invalidBackingValue(string $enumClass, string $value, ?Throwable $previous = null): self
    {
        return new self(
            enumClass: $enumClass,
            value: $value,
            context: 'not a valid backing value for this enum',
            previous: $previous,
        );
    }
}
