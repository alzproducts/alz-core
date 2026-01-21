<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\CustomFields;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;

/**
 * In-memory registry of custom field definitions keyed by name.
 *
 * Used by domain factories to look up field types when transforming
 * raw API custom field values into typed value objects.
 *
 * Typical usage:
 * 1. Load definitions from repository for specific item type
 * 2. Create registry with forItemType()
 * 3. Look up definitions by name when processing raw values
 */
final readonly class CustomFieldDefinitionRegistry
{
    /**
     * @param array<string, CustomFieldDefinition> $byName Definitions indexed by name
     */
    private function __construct(
        private array $byName,
    ) {}

    /**
     * Create a registry from a list of definitions, filtering by item type.
     *
     * @param list<CustomFieldDefinition> $definitions All definitions from repository
     * @param CustomFieldItemType $itemType Item type to filter for (e.g., Product)
     */
    public static function forItemType(array $definitions, CustomFieldItemType $itemType): self
    {
        $byName = [];

        foreach ($definitions as $definition) {
            if ($definition->itemType === $itemType) {
                $byName[$definition->name] = $definition;
            }
        }

        return new self($byName);
    }

    /**
     * Find a definition by its field name.
     */
    public function findByName(string $name): ?CustomFieldDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Check if a definition exists for the given name.
     */
    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Get all registered definition names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return \array_keys($this->byName);
    }

    /**
     * Get the count of registered definitions.
     */
    public function count(): int
    {
        return \count($this->byName);
    }
}
