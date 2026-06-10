<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
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
     * @param CustomFieldValueList $populatedFields Fields with values from the entity
     * @param list<ConfiguredFieldDefinition> $definitions All configured custom field definitions for the item type
     */
    public static function mergeWithDefinitions(CustomFieldValueList $populatedFields, array $definitions): CustomFieldValueList
    {
        $populatedByName = $populatedFields->mapByName();
        [$fields, $coveredNames] = self::buildDefinedFields($populatedByName, $definitions);
        $fields = self::appendUncoveredFields($fields, $populatedByName, $coveredNames);

        return CustomFieldValueList::from(self::sortByFieldOrder($fields));
    }

    /**
     * Use populated value where available, fall back to NullCustomFieldValue.
     *
     * @param array<string, AbstractCustomFieldValue> $populatedByName
     * @param list<ConfiguredFieldDefinition> $definitions
     *
     * @return array{list<AbstractCustomFieldValue>, array<string, true>}
     */
    private static function buildDefinedFields(array $populatedByName, array $definitions): array
    {
        $fields = [];
        $coveredNames = [];
        foreach ($definitions as $definition) {
            $coveredNames[$definition->base->name] = true;
            $fields[] = $populatedByName[$definition->base->name]
                ?? new NullCustomFieldValue($definition);
        }

        return [$fields, $coveredNames];
    }

    /**
     * Append populated fields not covered by a definition (forward compatibility).
     *
     * @param list<AbstractCustomFieldValue> $fields
     * @param array<string, AbstractCustomFieldValue> $populatedByName
     * @param array<string, true> $coveredNames
     *
     * @return list<AbstractCustomFieldValue>
     */
    private static function appendUncoveredFields(array $fields, array $populatedByName, array $coveredNames): array
    {
        foreach ($populatedByName as $name => $field) {
            if (!isset($coveredNames[$name])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Sort by sortOrder ascending, null last.
     *
     * @param list<AbstractCustomFieldValue> $fields
     *
     * @return list<AbstractCustomFieldValue>
     */
    private static function sortByFieldOrder(array $fields): array
    {
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
