<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\AdditionalCostUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\NewAdditionalCostDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Add, update, and/or delete additional cost line items on a purchase order.
 *
 * Delegates to the single Modify_AdditionalCost endpoint which accepts
 * all three operations in one call.
 *
 * @template-pattern Application Use Case
 */
final readonly class ModifyPurchaseOrderAdditionalCostsUseCase
{
    public function __construct(
        private PurchaseOrderUpdateClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
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
    public function execute(
        Guid $purchaseId,
        array $itemsToAdd = [],
        array $itemsToUpdate = [],
        array $itemIdsToDelete = [],
    ): void {
        $this->logger->info('Modifying purchase order additional costs', [
            'purchase_id' => $purchaseId->value,
            'adding' => \count($itemsToAdd),
            'updating' => \count($itemsToUpdate),
            'deleting' => \count($itemIdsToDelete),
        ]);

        $this->client->modifyAdditionalCosts($purchaseId, $itemsToAdd, $itemsToUpdate, $itemIdsToDelete);

        $this->logger->info('Modified purchase order additional costs', [
            'purchase_id' => $purchaseId->value,
            'added' => \count($itemsToAdd),
            'updated' => \count($itemsToUpdate),
            'deleted' => \count($itemIdsToDelete),
        ]);
    }
}
