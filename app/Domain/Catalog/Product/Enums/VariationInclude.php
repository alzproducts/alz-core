<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Exceptions\Data\InvalidEnumValueException;

enum VariationInclude: string
{
    case SaleSettings = 'sale_settings';
    case Inventory = 'inventory';
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
     * @throws InvalidEnumValueException When value doesn't match any case
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }
}
