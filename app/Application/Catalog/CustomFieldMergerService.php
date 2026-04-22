<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;

/**
 * Shared helper for merging populated custom fields with definitions.
 *
 * Ensures every defined field is represented in the result. Populated fields not in
 * definitions are appended for forward compatibility. Result is sorted by sortOrder (null last).
 */
final class CustomFieldMergerService
{
    /**
     * @param list<AbstractCustomFieldValue> $populatedFields Fields with values from the entity
     * @param list<ConfiguredFieldDefinition> $definitions All configured custom field definitions for the item type
     *
     * @return list<AbstractCustomFieldValue>
     */
    public static function mergeWithDefinitions(array $populatedFields, array $definitions): array
    {
        // Index populated fields by name for O(1) lookup
        $populatedByName = [];
        foreach ($populatedFields as $field) {
            $populatedByName[$field->name()] = $field;
        }

        // Build merged list: use populated value or create NullCustomFieldValue
        $fields = [];
        $coveredNames = [];
        foreach ($definitions as $definition) {
            $coveredNames[$definition->base->name] = true;
            $fields[] = $populatedByName[$definition->base->name]
                ?? new NullCustomFieldValue($definition);
        }

        // Append populated fields not in definitions (forward compatibility)
        foreach ($populatedByName as $name => $field) {
            if (!isset($coveredNames[$name])) {
                $fields[] = $field;
            }
        }

        // Sort by sortOrder (null last)
        \usort($fields, static function (AbstractCustomFieldValue $a, AbstractCustomFieldValue $b): int {
            $aSort = $a->definition->base->sortOrder;
            $bSort = $b->definition->base->sortOrder;

            if ($aSort === null && $bSort === null) {
                return 0;
            }
            if ($aSort === null) {
                return 1;
            }
            if ($bSort === null) {
                return -1;
            }

            return $aSort <=> $bSort;
        });

        return $fields;
    }
}
