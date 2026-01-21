<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Product Variation Option Value Object.
 *
 * Represents a single option attribute on a variation (e.g., "Color: Red").
 * A variation can have multiple options (e.g., Size + Color).
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
final readonly class ProductVariationOption
{
    /**
     * @param int $optionId ShopWired option type ID (e.g., ID for "Color")
     * @param string $optionName Human-readable option name (e.g., "Color")
     * @param int $valueId ShopWired value ID (e.g., ID for "Red")
     * @param string $valueName Human-readable value (e.g., "Red")
     */
    public function __construct(
        public int $optionId,
        public string $optionName,
        public int $valueId,
        public string $valueName,
    ) {
        // Note: ShopWired can return 0 for option/value IDs in some edge cases
        Assert::greaterThanEq($optionId, 0, 'Option ID cannot be negative');
        Assert::notEmpty($optionName, 'Option name cannot be empty');
        Assert::greaterThanEq($valueId, 0, 'Value ID cannot be negative');
        Assert::notEmpty($valueName, 'Value name cannot be empty');
    }

    /**
     * Get a display string for this option (e.g., "Color: Red").
     */
    public function toDisplayString(): string
    {
        return "{$this->optionName}: {$this->valueName}";
    }

    /**
     * Convert to array for JSONB storage.
     *
     * @return array{option_id: int, option_name: string, value_id: int, value_name: string}
     */
    public function toArray(): array
    {
        return [
            'option_id' => $this->optionId,
            'option_name' => $this->optionName,
            'value_id' => $this->valueId,
            'value_name' => $this->valueName,
        ];
    }

    /**
     * Create from array (JSONB hydration).
     *
     * @param array{option_id: int, option_name: string, value_id: int, value_name: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            optionId: $data['option_id'],
            optionName: $data['option_name'],
            valueId: $data['value_id'],
            valueName: $data['value_name'],
        );
    }
}
