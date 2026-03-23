<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\Services\ExtendedPropertyDiffService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Accept desired EP state, diff against current, and apply changes.
 *
 * Uses ExtendedPropertyDiffService to compute creates/updates/deletes,
 * then calls the appropriate client methods.
 *
 * @template-pattern Application Use Case
 */
final readonly class UpdatePurchaseOrderExtendedPropertiesUseCase
{
    public function __construct(
        private PurchaseOrderClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<DesiredExtendedPropertyDTO> $desired
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function execute(Guid $purchaseId, array $desired): void
    {
        $current = $this->client->getPurchaseOrderExtendedProperties($purchaseId);
        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        if ($changeset->isEmpty()) {
            $this->logger->info('Extended properties already up to date', [
                'purchase_id' => $purchaseId->value,
            ]);

            return;
        }

        $this->logger->info('Updating purchase order extended properties', [
            'purchase_id' => $purchaseId->value,
            'creating' => \count($changeset->toCreate),
            'updating' => \count($changeset->toUpdate),
            'deleting' => \count($changeset->toDelete),
        ]);

        if ($changeset->toCreate !== []) {
            $this->client->addPurchaseOrderExtendedProperties($purchaseId, $changeset->toCreate);
        }

        if ($changeset->toUpdate !== []) {
            $this->client->updatePurchaseOrderExtendedProperties($purchaseId, $changeset->toUpdate);
        }

        if ($changeset->toDelete !== []) {
            $this->client->deletePurchaseOrderExtendedProperties($purchaseId, $changeset->toDelete);
        }

        $this->logger->info('Updated purchase order extended properties', [
            'purchase_id' => $purchaseId->value,
            'created' => \count($changeset->toCreate),
            'updated' => \count($changeset->toUpdate),
            'deleted' => \count($changeset->toDelete),
        ]);
    }
}
