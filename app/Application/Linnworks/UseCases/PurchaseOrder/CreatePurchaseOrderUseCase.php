<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\ValueObjects\Guid;
use JsonException;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Throwable;

/**
 * Create a complete purchase order in a single operation.
 *
 * Orchestrates: create initial PO → add line items → optionally add EPs.
 * On partial failure: deletes the PO and rethrows the original exception.
 * Consumer sees either full success or full failure — no partial POs left behind.
 *
 * @template-pattern Application Use Case
 */
final readonly class CreatePurchaseOrderUseCase
{
    public function __construct(
        private PurchaseOrderClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return Guid The new purchase order ID
     *
     * @throws JsonException When JSON encoding fails
     * @throws RandomException When random number generation fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function execute(CreatePurchaseOrderCommand $command): Guid
    {
        $reference = $command->externalInvoiceNumber !== null
            ? PurchaseOrderReference::fromString($command->externalInvoiceNumber)
            : PurchaseOrderReference::generate();

        $purchaseId = $this->client->createPurchaseOrderInitial($command, $reference);

        $this->logger->info('Purchase order created', [
            'purchaseId' => $purchaseId->value,
            'reference' => $reference->value,
            'supplierId' => $command->fkSupplierId->value,
            'itemCount' => \count($command->items),
        ]);

        try {
            $this->addLineItems($purchaseId, $command->items);
            $this->addExtendedProperties($purchaseId, $command);
        } catch (Throwable $e) {
            $this->cleanupFailedPurchaseOrder($purchaseId, $e);

            throw $e;
        }

        $this->logger->info('Purchase order creation completed', [
            'purchase_id' => $purchaseId->value,
            'reference' => $reference->value,
            'item_count' => \count($command->items),
            'extended_property_count' => \count($command->extendedProperties),
        ]);

        return $purchaseId;
    }

    /**
     * Add line items to the PO.
     *
     * @param list<PurchaseOrderLineItemDTO> $items
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    private function addLineItems(Guid $purchaseId, array $items): void
    {
        foreach ($items as $item) {
            $this->client->addPurchaseOrderItem($purchaseId, $item);
        }
    }

    /**
     * Add extended properties if any were specified.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    private function addExtendedProperties(Guid $purchaseId, CreatePurchaseOrderCommand $command): void
    {
        if ($command->extendedProperties === []) {
            return;
        }

        $this->client->addPurchaseOrderExtendedProperties($purchaseId, $command->extendedProperties);
    }

    /**
     * Delete a partially-created PO to avoid orphaned records.
     */
    private function cleanupFailedPurchaseOrder(Guid $purchaseId, Throwable $originalException): void
    {
        try {
            $this->client->deletePurchaseOrder($purchaseId);
            $this->logger->warning('Deleted partially-created purchase order after failure', [
                'purchaseId' => $purchaseId->value,
                'error' => $originalException->getMessage(),
            ]);
        } catch (Throwable $cleanupException) { // @ignoreException - cleanup must not throw; original exception is rethrown by caller
            $this->logger->error('Failed to clean up partially-created purchase order', [
                'purchaseId' => $purchaseId->value,
                'originalError' => $originalException->getMessage(),
                'cleanupError' => $cleanupException->getMessage(),
            ]);
        }
    }
}
