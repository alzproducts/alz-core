<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Filters;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;

/**
 * In-memory registry of filter group definitions keyed by optionNo.
 *
 * Used by ProductFilterFactory to look up filter group metadata when
 * transforming raw product filter values into typed ProductFilter objects.
 *
 * Typical usage:
 * 1. Load definitions from repository
 * 2. Create registry with fromDefinitions()
 * 3. Look up definitions by optionNo when processing raw filter values
 */
final readonly class FilterGroupRegistry
{
    /**
     * @param array<int, FilterGroupDefinition> $byOptionNo Definitions indexed by optionNo
     */
    private function __construct(
        private array $byOptionNo,
    ) {}

    /**
     * Create a registry from a list of definitions.
     *
     * @param list<FilterGroupDefinition> $definitions All definitions from repository
     */
    public static function fromDefinitions(array $definitions): self
    {
        $byOptionNo = [];

        foreach ($definitions as $definition) {
            $byOptionNo[$definition->optionNo] = $definition;
        }

        return new self($byOptionNo);
    }

    /**
     * Find a definition by its optionNo.
     */
    public function findByOptionNo(int $optionNo): ?FilterGroupDefinition
    {
        return $this->byOptionNo[$optionNo] ?? null;
    }

    /**
     * Check if a definition exists for the given optionNo.
     */
    public function has(int $optionNo): bool
    {
        return isset($this->byOptionNo[$optionNo]);
    }

    /**
     * Get the count of registered definitions.
     */
    public function count(): int
    {
        return \count($this->byOptionNo);
    }
}
