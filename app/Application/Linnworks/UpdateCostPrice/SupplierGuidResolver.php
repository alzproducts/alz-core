<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UpdateCostPrice;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\Supplier;
use App\Domain\ValueObjects\Guid;

/**
 * Resolves a supplier name to its Linnworks GUID.
 *
 * Fetches the master supplier list and finds the matching entry by name.
 */
final readonly class SupplierGuidResolver
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
    ) {}

    /**
     * @throws ResourceNotFoundException When supplier not found
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     */
    public function resolve(string $supplierName): Guid
    {
        $suppliers = $this->inventoryClient->getSuppliers();

        $supplier = \array_find(
            $suppliers,
            static fn(Supplier $s): bool => $s->supplierName === $supplierName,
        );

        if ($supplier === null) {
            throw new ResourceNotFoundException(
                serviceName: 'Linnworks',
                resourceType: 'supplier',
                resourceId: $supplierName,
            );
        }

        return new Guid($supplier->pkSupplierId);
    }
}
