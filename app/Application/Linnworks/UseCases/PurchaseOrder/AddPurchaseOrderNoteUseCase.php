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
 * Attach a text note to a purchase order.
 *
 * @template-pattern Application Use Case
 */
final readonly class AddPurchaseOrderNoteUseCase
{
    public function __construct(
        private PurchaseOrderUpdateClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function execute(Guid $purchaseId, string $note): void
    {
        $this->logger->info('Adding note to purchase order', [
            'purchase_id' => $purchaseId->value,
        ]);

        $this->client->addPurchaseOrderNote($purchaseId, $note);

        $this->logger->info('Added note to purchase order', [
            'purchase_id' => $purchaseId->value,
        ]);
    }
}
