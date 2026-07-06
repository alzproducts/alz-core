<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\Enums\ExternalIdScope;
use App\Application\Shopwired\Results\ReconcileResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use Psr\Log\LoggerInterface;

/**
 * Remove orphaned products that no longer exist in ShopWired.
 *
 * Compares local product IDs against ShopWired API and deletes any that
 * exist locally but not in ShopWired. This handles products deleted from
 * the ShopWired admin or recreated with the same SKU.
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
     * @throws ResourceNotAvailableException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(): ReconcileResult
    {
        $this->logger->info('Starting product reconciliation from ShopWired');

        // Lightweight — IDs only, avoids fetching full product payloads
        $apiProductIds = $this->productClient->getAllProductIds();

        $localProductIds = $this->productRepository->getAllExternalIds(ExternalIdScope::Product);

        // Abort if the API returned nothing but local has products — protects
        // against mass deletion triggered by a silent API failure
        if ($apiProductIds === [] && $localProductIds !== []) {
            $this->logger->warning('Product reconciliation aborted: API returned 0 products but local DB has products', [
                'local_count' => \count($localProductIds),
                'action' => 'Skipping deletion to prevent data loss - investigate API response',
            ]);

            return ReconcileResult::skipped(localCount: \count($localProductIds));
        }

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

        // Variations cascade-deleted via FK constraint
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
