<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\Enums;

use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Embeddable relations and enrichments for category read queries.
 *
 * Defines the canonical set of includes available on category GET endpoints.
 * Each case maps to a string value used for serialization and HTTP parameter parsing.
 */
enum CategoryInclude: string
{
    case Description = 'description';
    case Description2 = 'description2';
    case ParentIds = 'parent_ids';
    case CustomFields = 'custom_fields';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Create from backing value with domain exception.
     *
     * Use instead of ::from() when you want domain exceptions
     * rather than PHP's ValueError.
     *
     * @throws InvalidEnumValueException When value doesn't match any case
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }
}
