<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Linnworks\DTOs\PurchaseOrder\AdditionalCostUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\NewAdditionalCostDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderHeaderUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\ValueObjects\Guid;
use JsonException;

/**
 * Contract for Linnworks PurchaseOrder API operations.
 *
 * Wraps all 17 Linnworks /api/PurchaseOrder/* endpoints.
 * Write use cases depend on this interface; read use cases deferred to sync plan.
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseOrderClientInterface
{
    // ── Read Operations ──

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

    // ── Write Operations ──

    /**
     * Create a new purchase order (initial step — no items yet).
     *
     * @return Guid The new pkPurchaseId
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function createPurchaseOrderInitial(CreatePurchaseOrderCommand $command, PurchaseOrderReference $reference): Guid;

    /**
     * Add a single line item to a purchase order.
     *
     * Note: The Linnworks API has no batch endpoint for items — each item requires
     * a separate HTTP call. Callers adding multiple items should expect O(n) latency.
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function addPurchaseOrderItem(Guid $purchaseId, PurchaseOrderLineItemDTO $item): void;

    /**
     * Change a purchase order's status.
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function changePurchaseOrderStatus(Guid $purchaseId, PurchaseOrderStatus $status): void;

    /**
     * Update a purchase order's header fields.
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function updatePurchaseOrderHeader(PurchaseOrderHeaderUpdateDTO $params): void;

    /**
     * Add extended properties to a purchase order.
     *
     * @param list<DesiredExtendedPropertyDTO> $properties
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function addPurchaseOrderExtendedProperties(Guid $purchaseId, array $properties): void;

    /**
     * Update extended properties on a purchase order.
     *
     * @param list<ExtendedPropertyUpdateDTO> $properties
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function updatePurchaseOrderExtendedProperties(Guid $purchaseId, array $properties): void;

    /**
     * Delete extended properties from a purchase order.
     *
     * @param list<int> $rowIds EP row IDs to delete
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function deletePurchaseOrderExtendedProperties(Guid $purchaseId, array $rowIds): void;

    /**
     * Add, update, and/or delete additional costs on a purchase order.
     *
     * @param list<NewAdditionalCostDTO> $itemsToAdd
     * @param list<AdditionalCostUpdateDTO> $itemsToUpdate
     * @param list<int> $itemIdsToDelete
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function modifyAdditionalCosts(
        Guid $purchaseId,
        array $itemsToAdd = [],
        array $itemsToUpdate = [],
        array $itemIdsToDelete = [],
    ): void;

    /**
     * Add a note to a purchase order.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function addPurchaseOrderNote(Guid $purchaseId, string $note): void;

    /**
     * Delete a purchase order.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function deletePurchaseOrder(Guid $purchaseId): void;
}
