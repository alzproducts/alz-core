<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs\PurchaseOrder;

/**
 * Desired state for a purchase order extended property.
 *
 * Used as input to the EP diff service — represents what the EP should be,
 * not what it currently is.
 */
final readonly class DesiredExtendedPropertyDTO
{
    public function __construct(
        public string $propertyName,
        public string $propertyValue,
    ) {}

    /**
     * @return array{PropertyName: string, PropertyValue: string}
     */
    public function forApi(): array
    {
        return [
            'PropertyName' => $this->propertyName,
            'PropertyValue' => $this->propertyValue,
        ];
    }
}
