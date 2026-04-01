<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Embeddable relations and enrichments for product read queries.
 *
 * Defines the canonical set of includes available on product GET endpoints.
 * Each case maps to a string value used for serialization and HTTP parameter parsing.
 */
enum ProductInclude: string
{
    case Variations = 'variations';
    case Description = 'description';
    case CategoryIds = 'category_ids';
    case CustomFields = 'custom_fields';
    case Filters = 'filters';
    case SaleSettings = 'sale_settings';
    case Suppliers = 'suppliers';

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
