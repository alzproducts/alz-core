<?php

declare(strict_types=1);

namespace App\Application\Linnworks\Services;

use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyChangesetDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyUpdateDTO;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;

/**
 * Diffs current vs desired extended properties to produce a changeset.
 *
 * Pure logic — no external calls, no exceptions. Matches properties by name
 * and produces create/update/delete operations.
 */
final class ExtendedPropertyDiffService
{
    /**
     * Diff current properties against desired state.
     *
     * @param list<PurchaseOrderExtendedProperty> $current Properties currently on the PO
     * @param list<DesiredExtendedPropertyDTO> $desired Desired final state
     */
    public static function diff(array $current, array $desired): ExtendedPropertyChangesetDTO
    {
        $currentByName = [];
        foreach ($current as $ep) {
            $currentByName[$ep->propertyName] = $ep;
        }

        $desiredByName = [];
        foreach ($desired as $ep) {
            $desiredByName[$ep->propertyName] = $ep;
        }

        [$toCreate, $toUpdate] = self::resolveCreatesAndUpdates($desiredByName, $currentByName);

        // Properties in current but not in desired → delete
        $toDelete = [];
        foreach ($currentByName as $name => $currentEp) {
            if (!isset($desiredByName[$name]) && $currentEp->rowId !== null) {
                $toDelete[] = $currentEp->rowId;
            }
        }

        return new ExtendedPropertyChangesetDTO($toCreate, $toUpdate, $toDelete);
    }

    /**
     * Determine which properties need creating or updating.
     *
     * @param array<string, DesiredExtendedPropertyDTO> $desiredByName
     * @param array<string, PurchaseOrderExtendedProperty> $currentByName
     *
     * @return array{list<DesiredExtendedPropertyDTO>, list<ExtendedPropertyUpdateDTO>}
     */
    private static function resolveCreatesAndUpdates(array $desiredByName, array $currentByName): array
    {
        $toCreate = [];
        $toUpdate = [];

        foreach ($desiredByName as $name => $desiredEp) {
            if (!isset($currentByName[$name])) {
                $toCreate[] = $desiredEp;

                continue;
            }

            $currentEp = $currentByName[$name];
            if ($currentEp->propertyValue !== $desiredEp->propertyValue && $currentEp->rowId !== null) {
                $toUpdate[] = new ExtendedPropertyUpdateDTO(
                    rowId: $currentEp->rowId,
                    propertyName: $desiredEp->propertyName,
                    propertyValue: $desiredEp->propertyValue,
                );
            }
        }

        return [$toCreate, $toUpdate];
    }
}
