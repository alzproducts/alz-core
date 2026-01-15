<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Extended property attached to a stock item.
 *
 * Represents a key-value property from Linnworks Extended Properties.
 * These are custom fields that merchants define for additional product
 * metadata (e.g., supplier codes, material types, certifications).
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItemExtendedProperty
{
    public function __construct(
        public string $rowId,
        public string $name,
        public string $value,
        public string $type,
    ) {
        Assert::notEmpty($rowId, 'Row ID cannot be empty');
        Assert::notEmpty($name, 'Property name cannot be empty');
    }

    /**
     * Check if this property has a non-empty value.
     */
    public function hasValue(): bool
    {
        return $this->value !== '';
    }
}
