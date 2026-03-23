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
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
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
    public function execute(
        Guid $purchaseId,
        ?string $supplierReferenceNumber = null,
        ?DateTimeImmutable $quotedDeliveryDate = null,
        ?float $postagePaid = null,
    ): void {
        $this->logger->info('Updating purchase order header', [
            'purchase_id' => $purchaseId->value,
            'fields' => \array_keys(\array_filter([
                'supplier_reference_number' => $supplierReferenceNumber,
                'quoted_delivery_date' => $quotedDeliveryDate,
                'postage_paid' => $postagePaid,
            ], static fn(mixed $v): bool => $v !== null)),
        ]);

        $current = $this->client->getPurchaseOrder($purchaseId);

        $updateParams = PurchaseOrderHeaderUpdateDTO::fromHeader(
            $current,
            $supplierReferenceNumber,
            $quotedDeliveryDate,
            $postagePaid,
        );

        $this->client->updatePurchaseOrderHeader($updateParams);

        $this->logger->info('Updated purchase order header', [
            'purchase_id' => $purchaseId->value,
        ]);
    }
}
