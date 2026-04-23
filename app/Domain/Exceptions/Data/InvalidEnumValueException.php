<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Override;
use Throwable;

/**
 * Enum value validation failed.
 *
 * Thrown when a provided value cannot be mapped to a valid enum case.
 * Used for user input that doesn't match expected enum values.
 */
final class InvalidEnumValueException extends AbstractDataException
{
    public readonly string $enumName;

    /**
     * @param class-string $enumClass
     */
    public function __construct(
        public readonly string $enumClass,
        public readonly string $value,
        public readonly string $usage,
        ?Throwable $previous = null,
    ) {
        $lastBackslash = \mb_strrchr($this->enumClass, '\\');
        $this->enumName = $lastBackslash !== false ? \mb_substr($lastBackslash, 1) : $this->enumClass;
        parent::__construct('Invalid enum value', 0, $previous);
    }

    #[Override]
    public function context(): array
    {
        return [
            'enum_class' => $this->enumClass,
            'enum_name' => $this->enumName,
            'value' => $this->value,
            'usage' => $this->usage,
        ];
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
            usage: 'no matching enum case found',
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
            usage: 'not a valid backing value for this enum',
            previous: $previous,
        );
    }
}
