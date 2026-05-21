<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\CreditProductPopularityRankingSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Override;

/**
 * Writes weekly credit product popularity snapshots into
 * `catalog.credit_product_popularity_snapshots` via a single INSERT...SELECT
 * from the `catalog.credit_product_popularity_ranking` view.
 *
 * Uses the default `pgsql` connection via DatabaseGateway — the `catalog`
 * schema has ALTER DEFAULT PRIVILEGES granting INSERT to `service_role`,
 * so no special connection routing is needed.
 *
 * Mirrors ProductPopularityRankingSnapshotRepository with the additional
 * `credit_tier` column as the final field in the column list.
 */
final readonly class CreditProductPopularityRankingSnapshotRepository implements CreditProductPopularityRankingSnapshotRepositoryInterface
{
    public function __construct(
        private DatabaseGateway $databaseGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function writeSnapshotForToday(): int
    {
        return $this->databaseGateway->runSql(self::buildInsertSql());
    }

    private static function buildInsertSql(): string
    {
        return self::buildInsertHeader() . self::buildSelectFromView();
    }

    private static function buildInsertHeader(): string
    {
        return <<<'SQL'
            INSERT INTO catalog.credit_product_popularity_snapshots (
                snapshot_date,
                algorithm_version,
                parent_external_id,
                sku,
                title,
                is_active,
                calculated_sort_order,
                current_sort_order,
                main_qty,
                main_turnover,
                recent_qty,
                recent_turnover,
                main_qty_rank,
                main_turnover_rank,
                recent_qty_rank,
                recent_turnover_rank,
                main_score,
                recent_score,
                final_score,
                trend,
                credit_tier
            )
            SQL;
    }

    private static function buildSelectFromView(): string
    {
        return <<<'SQL'
            SELECT
                CURRENT_DATE             AS snapshot_date,
                v.algorithm_version,
                v.parent_external_id,
                v.sku,
                v.title,
                v.is_active,
                v.calculated_sort_order,
                v.current_sort_order,
                v.main_qty,
                v.main_turnover,
                v.recent_qty,
                v.recent_turnover,
                v.main_qty_rank,
                v.main_turnover_rank,
                v.recent_qty_rank,
                v.recent_turnover_rank,
                v.main_score,
                v.recent_score,
                v.final_score,
                v.trend,
                v.credit_tier
            FROM catalog.credit_product_popularity_ranking v
            SQL;
    }
}
