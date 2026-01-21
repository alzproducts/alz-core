<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\ValueObjects\ReconcileResult;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Remove orphaned products that no longer exist in ShopWired.
 *
 * Compares local product IDs against ShopWired API and deletes any that
 * exist locally but not in ShopWired. This handles products deleted from
 * the ShopWired admin or recreated with the same SKU.
 *
 * Algorithm:
 * 1. Fetch all product external_ids from ShopWired API (lightweight - IDs only)
 * 2. Query local DB for all product external_ids
 * 3. Find orphans: local_ids - api_ids
 * 4. Delete orphaned products (cascade deletes variations)
 *
 * Usage:
 * - Run after main sync completes to clean up deleted products
 */
final readonly class ReconcileProductsUseCase
{
    public function __construct(
        private ProductClientInterface $productClient,
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Reconcile local products against ShopWired API.
     *
     * @return ReconcileResult Results with counts and orphan IDs
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws DatabaseOperationFailedException When database query/delete fails
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(): ReconcileResult
    {
        $this->logger->info('Starting product reconciliation from ShopWired');

        // Fetch all IDs from ShopWired (lightweight - IDs only)
        $apiProductIds = $this->productClient->getAllProductIds();

        // Fetch all local product IDs
        $localProductIds = $this->productRepository->getAllExternalIds();

        // Safety check: if API returns empty but we have local products, abort
        // This prevents accidental mass deletion if API fails silently
        if ($apiProductIds === [] && $localProductIds !== []) {
            $this->logger->warning('Product reconciliation aborted: API returned 0 products but local DB has products', [
                'local_count' => \count($localProductIds),
                'action' => 'Skipping deletion to prevent data loss - investigate API response',
            ]);

            return ReconcileResult::skipped(localCount: \count($localProductIds));
        }

        // Find orphans: products in local DB but not in ShopWired
        $orphanedIds = \array_values(\array_diff($localProductIds, $apiProductIds));

        if ($orphanedIds === []) {
            $this->logger->info('Product reconciliation completed: no orphans found', [
                'api_count' => \count($apiProductIds),
                'local_count' => \count($localProductIds),
            ]);

            return ReconcileResult::noOrphans(
                apiCount: \count($apiProductIds),
                localCount: \count($localProductIds),
            );
        }

        // Delete orphaned products (variations cascade-deleted via FK)
        /** @var int<0, max> $deletedCount */
        $deletedCount = $this->productRepository->deleteByExternalIds($orphanedIds);

        $this->logger->info('Product reconciliation completed: removed orphans', [
            'api_count' => \count($apiProductIds),
            'local_count' => \count($localProductIds),
            'orphans_found' => \count($orphanedIds),
            'orphans_deleted' => $deletedCount,
            'orphan_ids' => $orphanedIds,
        ]);

        return new ReconcileResult(
            apiCount: \count($apiProductIds),
            localCount: \count($localProductIds),
            orphansFound: \count($orphanedIds),
            orphansDeleted: $deletedCount,
            orphanIds: $orphanedIds,
        );
    }
}
