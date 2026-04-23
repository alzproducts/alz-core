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
        $currentByName = self::indexCurrentByName($current);
        $desiredByName = self::indexDesiredByName($desired);

        [$toCreate, $toUpdate] = self::resolveCreatesAndUpdates($desiredByName, $currentByName);
        $toDelete = self::resolveDeletions($currentByName, $desiredByName);

        return new ExtendedPropertyChangesetDTO($toCreate, $toUpdate, $toDelete);
    }

    /**
     * @param list<PurchaseOrderExtendedProperty> $current
     *
     * @return array<string, PurchaseOrderExtendedProperty>
     */
    private static function indexCurrentByName(array $current): array
    {
        $indexed = [];
        foreach ($current as $ep) {
            $indexed[$ep->propertyName] = $ep;
        }

        return $indexed;
    }

    /**
     * @param list<DesiredExtendedPropertyDTO> $desired
     *
     * @return array<string, DesiredExtendedPropertyDTO>
     */
    private static function indexDesiredByName(array $desired): array
    {
        $indexed = [];
        foreach ($desired as $ep) {
            $indexed[$ep->propertyName] = $ep;
        }

        return $indexed;
    }

    /**
     * Properties present in current but absent from desired → delete.
     *
     * @param array<string, PurchaseOrderExtendedProperty> $currentByName
     * @param array<string, DesiredExtendedPropertyDTO> $desiredByName
     *
     * @return list<int>
     */
    private static function resolveDeletions(array $currentByName, array $desiredByName): array
    {
        $toDelete = [];
        foreach ($currentByName as $name => $currentEp) {
            if (!isset($desiredByName[$name]) && $currentEp->rowId !== null) {
                $toDelete[] = $currentEp->rowId;
            }
        }

        return $toDelete;
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

            $update = self::diffExistingProperty($currentByName[$name], $desiredEp);

            if ($update !== null) {
                $toUpdate[] = $update;
            }
        }

        return [$toCreate, $toUpdate];
    }

    /**
     * Build an update DTO if the desired value differs from current, else null.
     */
    private static function diffExistingProperty(
        PurchaseOrderExtendedProperty $currentEp,
        DesiredExtendedPropertyDTO $desiredEp,
    ): ?ExtendedPropertyUpdateDTO {
        if ($currentEp->propertyValue === $desiredEp->propertyValue || $currentEp->rowId === null) {
            return null;
        }

        return new ExtendedPropertyUpdateDTO(
            rowId: $currentEp->rowId,
            propertyName: $desiredEp->propertyName,
            propertyValue: $desiredEp->propertyValue,
        );
    }
}
