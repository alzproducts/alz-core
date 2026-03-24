<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * Result of diffing current vs desired extended properties.
 *
 * Produced by ExtendedPropertyDiffService, consumed by UpdatePurchaseOrderExtendedPropertiesUseCase.
 */
final readonly class ExtendedPropertyChangesetDTO
{
    /**
     * @param list<DesiredExtendedPropertyDTO> $toCreate New properties to add
     * @param list<ExtendedPropertyUpdateDTO> $toUpdate Existing properties to update
     * @param list<int> $toDelete Row IDs of properties to remove
     */
    public function __construct(
        public array $toCreate = [],
        public array $toUpdate = [],
        public array $toDelete = [],
    ) {}

    /**
     * Check whether there are any changes to apply.
     */
    public function isEmpty(): bool
    {
        return $this->toCreate === [] && $this->toUpdate === [] && $this->toDelete === [];
    }
}
