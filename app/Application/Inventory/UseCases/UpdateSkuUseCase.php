<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Operations\SkuChangeRepositoryInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Domain\Inventory\Enums\SkuUpdateType;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates cross-platform SKU updates with compensating transactions.
 *
 * Flow:
 * 1. Generate new SKU if needed (type=Generated)
 * 2. Create audit record (marks intent)
 * 3. Update Linnworks (source of truth)
 * 4. Update ShopWired
 * 5. On ShopWired failure: compensate Linnworks, re-throw original
 *
 * ⚠️ PRODUCTION ONLY: This use case modifies LIVE Linnworks and ShopWired data.
 * The audit trail (operations.sku_changes) is critical for tracking changes
 * used in historic sales reports. Running locally writes audit records to your
 * local database while making REAL changes to production systems - leaving no
 * traceable record in production.
 *
 * Exception Strategy:
 * - Original exceptions bubble up for job retry logic
 * - SkuUpdateFailedException thrown ONLY when compensation fails (no retry!)
 *
 * Note: Uses business identifiers (SKUs) only. Infrastructure layers
 * resolve external system IDs (stockItemId, productId) internally.
 */
final readonly class UpdateSkuUseCase
{
    private const int LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private BasicProductUpdateClientInterface $shopwiredClient,
        private SkuChangeRepositoryInterface $auditRepository,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute a cross-platform SKU update.
     *
     * @throws InvalidSkuException When command validation fails (permanent failure)
     * @throws SkuGenerationFailedException When auto-generation fails
     * @throws LockAcquisitionException When SKU generation lock cannot be acquired
     * @throws SkuUpdateFailedException When compensation fails (systems out of sync - DO NOT RETRY)
     * @throws ResourceNotFoundException When SKU not found in either system
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When services unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws DatabaseOperationFailedException When audit record operations fail
     */
    public function execute(UpdateSkuCommand $command): void
    {
        $oldSkuValue = Sku::fromTrusted($command->oldSku);

        // Lock critical section: SKU generation + Linnworks update
        // This prevents race conditions where two processes generate the same SKU
        [$newSku, $auditId] = $this->lockManager->withLock(
            LockName::SkuGeneration->value,
            self::LOCK_TIMEOUT_SECONDS,
            function () use ($command, $oldSkuValue): array {
                // 1. Resolve the new SKU value
                $newSku = $this->resolveNewSku($command);

                $this->logger->info('Starting SKU update', [
                    'old_sku' => $command->oldSku,
                    'new_sku' => $newSku->value,
                    'type' => $command->type->value,
                    'reason' => $command->reason->value,
                ]);

                // 2. Create audit record (marks intent before any mutations)
                $auditId = $this->auditRepository->create(
                    oldSku: $command->oldSku,
                    newSku: $newSku,
                    reason: $command->reason,
                );

                // 3. Update Linnworks first (source of truth, easier to revert)
                try {
                    $this->inventoryUpdateClient->updateSku($oldSkuValue, $newSku);
                } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
                    $this->logger->error('Linnworks SKU update failed', [
                        'old_sku' => $command->oldSku,
                        'new_sku' => $newSku->value,
                        'error' => $e->getMessage(),
                    ]);
                    $this->auditRepository->recordError($auditId, "Linnworks failed: {$e->getMessage()}");

                    throw $e;
                }

                return [$newSku, $auditId];
            },
        );

        // 4. Update ShopWired (with compensation on failure) - outside lock
        try {
            $this->shopwiredClient->update(new UpdateBasicProductCommand(
                identifier: $oldSkuValue,
                newSku: $newSku,
            ));
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            $this->compensateAndRethrow($auditId, $command->oldSku, $newSku, $e);
        }

        // 5. Mark complete
        $this->auditRepository->markComplete($auditId);

        $this->logger->info('SKU update completed successfully', [
            'old_sku' => $command->oldSku,
            'new_sku' => $newSku->value,
        ]);
    }

    /**
     * Resolve the new SKU (generate if needed, otherwise use provided).
     *
     * @throws InvalidSkuException When type=Provided but newSku is missing
     * @throws SkuGenerationFailedException When auto-generation fails
     */
    private function resolveNewSku(UpdateSkuCommand $command): Sku
    {
        if ($command->type === SkuUpdateType::Provided) {
            return $command->getProvidedSku();
        }

        try {
            return $this->inventoryClient->getNewItemNumber();
        } catch (AuthenticationExpiredException|ExternalServiceUnavailableException|InvalidApiResponseException $e) {
            throw new SkuGenerationFailedException($e->getMessage(), $e);
        }
    }

    /**
     * Compensate Linnworks update after ShopWired failure.
     *
     * If compensation succeeds: re-throws original exception (job can retry).
     * If compensation fails: throws SkuUpdateFailedException (job must NOT retry).
     *
     * @throws SkuUpdateFailedException When compensation fails (systems out of sync)
     * @throws ResourceNotFoundException Re-thrown when compensation succeeds
     * @throws InvalidApiRequestException Re-thrown when compensation succeeds
     * @throws InvalidApiResponseException Re-thrown when compensation succeeds
     * @throws AuthenticationExpiredException Re-thrown when compensation succeeds
     * @throws ExternalServiceUnavailableException Re-thrown when compensation succeeds
     * @throws DatabaseOperationFailedException When audit record update fails
     */
    private function compensateAndRethrow(
        string $auditId,
        string $oldSku,
        Sku $newSku,
        ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $originalError,
    ): never {
        $this->logger->warning('ShopWired update failed, attempting Linnworks compensation', [
            'old_sku' => $oldSku,
            'new_sku' => $newSku->value,
            'error' => $originalError->getMessage(),
        ]);

        $oldSkuValue = Sku::fromTrusted($oldSku);

        try {
            // Revert: newSku → oldSku (Linnworks currently has newSku)
            $this->inventoryUpdateClient->updateSku($newSku, $oldSkuValue);

            $this->logger->info('Linnworks compensation successful', [
                'reverted_to' => $oldSku,
            ]);

            $this->auditRepository->recordError(
                $auditId,
                "ShopWired failed (compensated): {$originalError->getMessage()}",
            );

            // Compensation succeeded - re-throw original for job retry logic
            throw $originalError;
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $compensationError) {
            // CRITICAL: Systems are now out of sync - manual intervention required
            $this->logger->critical('COMPENSATION FAILED - manual intervention required', [
                'linnworks_has' => $newSku->value,
                'shopwired_has' => $oldSku,
                'original_error' => $originalError->getMessage(),
                'compensation_error' => $compensationError->getMessage(),
            ]);

            $this->auditRepository->recordError(
                $auditId,
                "ShopWired failed + compensation failed: {$compensationError->getMessage()}",
            );

            // Compensation failed - throw specific exception to prevent retries
            throw new SkuUpdateFailedException(
                oldSku: $oldSku,
                newSku: $newSku->value,
                failedSystem: 'shopwired+compensation',
                reason: "Original: {$originalError->getMessage()}, Compensation: {$compensationError->getMessage()}",
                previous: $compensationError,
            );
        }
    }
}
