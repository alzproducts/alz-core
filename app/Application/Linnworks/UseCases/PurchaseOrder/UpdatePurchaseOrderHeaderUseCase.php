<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderHeaderUpdateDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Update one or more header fields on an existing purchase order.
 *
 * Fetches current PO header, applies field changes via PurchaseOrderHeaderUpdateDTO,
 * then pushes the full header update. The Linnworks API requires the full header object.
 *
 * @template-pattern Application Use Case
 */
final readonly class UpdatePurchaseOrderHeaderUseCase
{
    public function __construct(
        private PurchaseOrderClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws JsonException When JSON encoding fails
     * @throws ResourceNotFoundException When PO not found
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function execute(UpdatePurchaseOrderHeaderCommand $command): void
    {
        $this->logger->info('Updating purchase order header', [
            'purchase_id' => $command->purchaseId->value,
            'fields' => $command->changedFields(),
        ]);

        $current = $this->client->getPurchaseOrder($command->purchaseId);

        $updateParams = PurchaseOrderHeaderUpdateDTO::fromHeaderWithOverrides($current, $command);

        $this->client->updatePurchaseOrderHeader($updateParams);

        $this->logger->info('Updated purchase order header', [
            'purchase_id' => $command->purchaseId->value,
        ]);
    }
}
