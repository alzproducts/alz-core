<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Filters\ValueObjects;

/**
 * A typed product filter with its group definition and values.
 *
 * Represents a filter assigned to a product, combining the filter group
 * metadata (from FilterGroupDefinition) with the product's selected values.
 *
 * Example: A product might have filter group "Size" (optionNo=1) with
 * values ["Small", "Medium", "Large"].
 */
final readonly class ProductFilter
{
    /**
     * @param FilterGroupDefinition $definition The filter group definition
     * @param list<string> $values Selected filter values for this product
     */
    public function __construct(
        public FilterGroupDefinition $definition,
        public array $values,
    ) {}

    /**
     * Get the filter group title (e.g., "Size", "Colour").
     */
    public function title(): string
    {
        return $this->definition->title;
    }

    /**
     * Get the option number used as key in raw filter data.
     */
    public function optionNo(): int
    {
        return $this->definition->optionNo;
    }

    /**
     * Check if this filter has any values assigned.
     */
    public function hasValues(): bool
    {
        return $this->values !== [];
    }

    /**
     * Check if a specific value is selected for this filter.
     */
    public function hasValue(string $value): bool
    {
        return \in_array($value, $this->values, true);
    }

    /**
     * Get the first value, or null if empty.
     */
    public function firstValue(): ?string
    {
        return $this->values[0] ?? null;
    }

    /**
     * Serialize to API-friendly array.
     *
     * @return array{title: string, values: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title(),
            'values' => $this->values,
        ];
    }
}
