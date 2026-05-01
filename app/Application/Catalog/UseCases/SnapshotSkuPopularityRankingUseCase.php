<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\SkuPopularityRankingSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SnapshotSkuPopularityRankingUseCase
{
    public function __construct(
        private SkuPopularityRankingSnapshotRepositoryInterface $snapshotRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SnapshotSkuPopularityRanking: starting');

        $rowsWritten = $this->snapshotRepository->writeSnapshotForToday();

        if ($rowsWritten === 0) {
            // Zero rows = no active config row (catalog.sku_popularity_ranking_config
            // WHERE is_active = true returned nothing). Treat as a hard failure so silent
            // zero-row snapshots are caught on the very first bad run rather than weeks later.
            throw new DatabaseOperationFailedException(
                operation: 'sku_popularity_ranking_snapshot',
                reason: 'no_active_config_row',
            );
        }

        $this->logger->info('SnapshotSkuPopularityRanking: completed', [
            'rows_written' => $rowsWritten,
        ]);
    }
}
