<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases\PurchaseOrder;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyChangesetDTO;
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
        private PurchaseOrderClientInterface $readClient,
        private PurchaseOrderUpdateClientInterface $writeClient,
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
        $current = $this->readClient->getPurchaseOrderExtendedProperties($purchaseId);
        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        if ($changeset->isEmpty()) {
            $this->logger->info('Extended properties already up to date', ['purchase_id' => $purchaseId->value]);

            return;
        }

        $this->logChangeset('Updating purchase order extended properties', $purchaseId, $changeset, present: true);
        $this->applyChangeset($purchaseId, $changeset);
        $this->logChangeset('Updated purchase order extended properties', $purchaseId, $changeset, present: false);
    }

    private function logChangeset(string $event, Guid $purchaseId, ExtendedPropertyChangesetDTO $changeset, bool $present): void
    {
        $this->logger->info($event, [
            'purchase_id' => $purchaseId->value,
            ...self::buildChangesetCounts($changeset, $present),
        ]);
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function applyChangeset(Guid $purchaseId, ExtendedPropertyChangesetDTO $changeset): void
    {
        if ($changeset->toCreate !== []) {
            $this->writeClient->addPurchaseOrderExtendedProperties($purchaseId, $changeset->toCreate);
        }

        if ($changeset->toUpdate !== []) {
            $this->writeClient->updatePurchaseOrderExtendedProperties($purchaseId, $changeset->toUpdate);
        }

        if ($changeset->toDelete !== []) {
            $this->writeClient->deletePurchaseOrderExtendedProperties($purchaseId, $changeset->toDelete);
        }
    }

    /**
     * @return array<string, int>
     */
    private static function buildChangesetCounts(ExtendedPropertyChangesetDTO $changeset, bool $present): array
    {
        return [
            $present ? 'creating' : 'created' => \count($changeset->toCreate),
            $present ? 'updating' : 'updated' => \count($changeset->toUpdate),
            $present ? 'deleting' : 'deleted' => \count($changeset->toDelete),
        ];
    }
}
