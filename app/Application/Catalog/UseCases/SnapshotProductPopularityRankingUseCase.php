<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\ProductPopularityRankingSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SnapshotProductPopularityRankingUseCase
{
    public function __construct(
        private ProductPopularityRankingSnapshotRepositoryInterface $snapshotRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SnapshotProductPopularityRanking: starting');

        $rowsWritten = $this->snapshotRepository->writeSnapshotForToday();

        if ($rowsWritten === 0) {
            // Zero rows = no active config row (catalog.product_popularity_ranking_config
            // WHERE is_active = true returned nothing). Treat as a hard failure so silent
            // zero-row snapshots are caught on the very first bad run rather than weeks later.
            throw new DatabaseOperationFailedException(
                operation: 'product_popularity_ranking_snapshot',
                reason: 'no_active_config_row',
            );
        }

        $this->logger->info('SnapshotProductPopularityRanking: completed', [
            'rows_written' => $rowsWritten,
        ]);
    }
}
