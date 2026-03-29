<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Add one or more stock items to an existing PENDING purchase order.
 *
 * Partial failure leaves already-added items in place (no rollback for
 * individual items on an existing PO).
 *
 * @template-pattern Application Use Case
 */
final readonly class AddPurchaseOrderItemsUseCase
{
    public function __construct(
        private PurchaseOrderUpdateClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<PurchaseOrderLineItemDTO> $items
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function execute(Guid $purchaseId, array $items): void
    {
        $this->logger->info('Adding items to purchase order', [
            'purchase_id' => $purchaseId->value,
            'item_count' => \count($items),
        ]);

        foreach ($items as $item) {
            $this->client->addPurchaseOrderItem($purchaseId, $item);
        }

        $this->logger->info('Added items to purchase order', [
            'purchase_id' => $purchaseId->value,
            'item_count' => \count($items),
        ]);
    }
}
