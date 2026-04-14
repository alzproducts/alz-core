<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Application\Contracts\Catalog\RelatedProductsAlgorithmParamsRepositoryInterface;
use App\Application\Contracts\Catalog\RelatedProductsQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

final readonly class SyncRelatedProductsUseCase
{
    public function __construct(
        private RelatedProductsAlgorithmParamsRepositoryInterface $paramsRepo,
        private RelatedProductsQueryRepositoryInterface $queryRepo,
        private ProductViewQueryRepositoryInterface $stateRepo,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When no active algorithm params row exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SyncRelatedProducts: starting');

        $params = $this->paramsRepo->getActiveParams();

        $desired = $this->queryRepo->computeRelatedProducts($params);
        $current = $this->stateRepo->getCurrentRelatedProducts();

        $dispatchedCount = $this->dispatchChanges($desired, $current);

        $allCount = \count(\array_unique(\array_merge(\array_keys($desired), \array_keys($current))));

        $this->logger->info('SyncRelatedProducts: dispatched updates', [
            'total_products' => $allCount,
            'dispatched_count' => $dispatchedCount,
            'unchanged_count' => $allCount - $dispatchedCount,
        ]);
    }

    /**
     * @param  array<int, list<IntId>>  $desired
     * @param  array<int, list<IntId>>  $current
     */
    private function dispatchChanges(array $desired, array $current): int
    {
        $allProductIds = \array_unique(\array_merge(\array_keys($desired), \array_keys($current)));
        $dispatchedCount = 0;

        foreach ($allProductIds as $productId) {
            if (self::idsMatch($desired[$productId] ?? [], $current[$productId] ?? [])) {
                continue;
            }

            $this->dispatcher->dispatchRelatedProductsUpdate(
                IntId::from($productId),
                $desired[$productId] ?? [],
            );

            ++$dispatchedCount;
        }

        return $dispatchedCount;
    }

    /**
     * @param  list<IntId>  $a
     * @param  list<IntId>  $b
     */
    private static function idsMatch(array $a, array $b): bool
    {
        $toValues = static fn(IntId $id): int => $id->value;

        return \array_map($toValues, $a) === \array_map($toValues, $b);
    }
}
