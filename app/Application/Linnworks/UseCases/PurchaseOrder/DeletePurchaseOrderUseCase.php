<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Delete a purchase order from Linnworks.
 *
 * @template-pattern Application Use Case
 */
final readonly class DeletePurchaseOrderUseCase
{
    public function __construct(
        private PurchaseOrderUpdateClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When PO not found
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function execute(Guid $purchaseId): void
    {
        $this->logger->info('Deleting purchase order', [
            'purchase_id' => $purchaseId->value,
        ]);

        $this->client->deletePurchaseOrder($purchaseId);

        $this->logger->info('Deleted purchase order', [
            'purchase_id' => $purchaseId->value,
        ]);
    }
}
