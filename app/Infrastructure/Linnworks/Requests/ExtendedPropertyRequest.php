<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Domain\ValueObjects\Guid;

/**
 * Structural mapping for Linnworks extended property write endpoints.
 *
 * Used for both CreateInventoryItemExtendedProperties and
 * UpdateInventoryItemExtendedProperties — same payload shape, different endpoint.
 *
 * Note: "ProperyName" typo is intentional — the Linnworks API expects this misspelling.
 */
final readonly class ExtendedPropertyRequest
{
    private function __construct(
        private string $stockItemId,
        private string $propertyName,
        private string $propertyValue,
        private ?string $rowId,
    ) {}

    public static function fromWrite(ExtendedPropertyWrite $property, Guid $stockItemId, ?string $rowId = null): self
    {
        return new self(
            stockItemId: $stockItemId->value,
            propertyName: $property->name->value,
            propertyValue: $property->value,
            rowId: $rowId,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        // Note: Linnworks API uses "ProperyName" (typo is intentional — API expects this)
        $payload = [
            'fkStockItemId' => $this->stockItemId,
            'ProperyName' => $this->propertyName,
            'PropertyValue' => $this->propertyValue,
            'PropertyType' => 'Attribute',
        ];

        if ($this->rowId !== null) {
            $payload['pkRowId'] = $this->rowId;
        }

        return $payload;
    }
}
