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
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\ValueObjects\Guid;
use JsonException;

/**
 * Contract for Linnworks PurchaseOrder write operations.
 *
 * Separated from read operations to enforce single-responsibility
 * and reduce class sizes (following InventoryUpdateClientInterface pattern).
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseOrderUpdateClientInterface
{
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
