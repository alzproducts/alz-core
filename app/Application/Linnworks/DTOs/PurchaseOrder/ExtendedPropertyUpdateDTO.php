<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * An existing extended property that needs its value updated.
 *
 * Produced by ExtendedPropertyDiffService when a property name matches
 * but the value has changed.
 */
final readonly class ExtendedPropertyUpdateDTO
{
    public function __construct(
        public int $rowId,
        public string $propertyName,
        public string $propertyValue,
    ) {}

    /**
     * @return array{RowId: int, PropertyName: string, PropertyValue: string}
     */
    public function forApi(): array
    {
        return [
            'RowId' => $this->rowId,
            'PropertyName' => $this->propertyName,
            'PropertyValue' => $this->propertyValue,
        ];
    }
}
