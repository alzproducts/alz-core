<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\Exceptions\InvalidPurchaseOrderStatusTransitionException;
use App\Domain\ValueObjects\Guid;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Transition a purchase order to a new status.
 *
 * Validates the transition against domain rules before calling the API.
 * Invalid transitions throw a domain exception — no API call is made.
 *
 * @template-pattern Application Use Case
 */
final readonly class ChangePurchaseOrderStatusUseCase
{
    public function __construct(
        private PurchaseOrderClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws JsonException When JSON encoding fails
     * @throws InvalidPurchaseOrderStatusTransitionException When transition is not allowed
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When PO not found
     */
    public function execute(Guid $purchaseId, PurchaseOrderStatus $currentStatus, PurchaseOrderStatus $targetStatus): void
    {
        if (!$currentStatus->canTransitionTo($targetStatus)) {
            throw new InvalidPurchaseOrderStatusTransitionException($currentStatus, $targetStatus);
        }

        $this->logger->info('Changing purchase order status', [
            'purchase_id' => $purchaseId->value,
            'from' => $currentStatus->value,
            'to' => $targetStatus->value,
        ]);

        $this->client->changePurchaseOrderStatus($purchaseId, $targetStatus);

        $this->logger->info('Changed purchase order status', [
            'purchase_id' => $purchaseId->value,
            'from' => $currentStatus->value,
            'to' => $targetStatus->value,
        ]);
    }
}
