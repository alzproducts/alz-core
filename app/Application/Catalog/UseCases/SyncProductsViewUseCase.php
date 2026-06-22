<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncProductsViewUseCase
{
    public function __construct(
        private ProductViewQueryRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('Refreshing catalog.products_view materialized view');

        $start = \microtime(true);

        $this->repository->refreshMaterializedView();

        $elapsed = \round(\microtime(true) - $start, 2);

        $this->logger->info('Refreshed catalog.products_view materialized view', [
            'elapsed_seconds' => $elapsed,
        ]);
    }
}
