<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\CreditProductPopularityRankingSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SnapshotCreditProductPopularityRankingUseCase
{
    public function __construct(
        private CreditProductPopularityRankingSnapshotRepositoryInterface $snapshotRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SnapshotCreditProductPopularityRanking: starting');

        $rowsWritten = $this->snapshotRepository->writeSnapshotForToday();

        if ($rowsWritten === 0) {
            // Zero rows = no active config row. Treat as a hard failure so a
            // silent zero-row snapshot is caught immediately, not weeks later.
            throw new DatabaseOperationFailedException(
                operation: 'credit_product_popularity_ranking_snapshot',
                reason: 'no_active_config_row',
            );
        }

        $this->logger->info('SnapshotCreditProductPopularityRanking: completed', [
            'rows_written' => $rowsWritten,
        ]);
    }
}
