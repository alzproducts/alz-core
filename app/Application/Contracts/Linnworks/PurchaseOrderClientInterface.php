<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Domain\ValueObjects\Guid;
use JsonException;

/**
 * Contract for Linnworks PurchaseOrder read operations.
 *
 * Write operations are defined in PurchaseOrderUpdateClientInterface.
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseOrderClientInterface
{
    /**
     * Get a purchase order by ID.
     *
     * @throws ResourceNotFoundException When PO doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getPurchaseOrder(Guid $purchaseId): PurchaseOrderHeader;

    /**
     * Search purchase orders by criteria.
     *
     * @param array<string, mixed> $searchParams Search criteria (dates, status, supplier, location, reference)
     *
     * @return array{results: list<array<string, mixed>>, totalRecords: int}
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function searchPurchaseOrders(array $searchParams): array;

    /**
     * Get extended properties for a purchase order.
     *
     * @return list<PurchaseOrderExtendedProperty>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrderExtendedProperties(Guid $purchaseId): array;

    /**
     * Get additional costs for a purchase order.
     *
     * @return list<PurchaseOrderAdditionalCost>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getAdditionalCosts(Guid $purchaseId): array;

    /**
     * Get available additional cost types.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getAdditionalCostTypes(): array;

    /**
     * Get notes for a purchase order.
     *
     * @return list<PurchaseOrderNote>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrderNotes(Guid $purchaseId): array;

    /**
     * Find purchase orders containing specific stock items.
     *
     * @param list<string> $locationIds Location GUIDs to search
     *
     * @return list<string> Purchase order IDs
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrdersWithStockItems(string $stockItemId, array $locationIds): array;
}
