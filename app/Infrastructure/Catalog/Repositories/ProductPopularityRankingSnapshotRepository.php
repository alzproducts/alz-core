<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\ProductPopularityRankingSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Override;

/**
 * Writes weekly product popularity ranking snapshots into
 * `catalog.product_popularity_snapshots` via a single INSERT...SELECT
 * from the `catalog.product_popularity_ranking` view.
 *
 * Uses the default `pgsql` connection via DatabaseGateway — the `catalog`
 * schema has ALTER DEFAULT PRIVILEGES granting INSERT to `service_role`
 * (see the catalog schema migration), so no special connection routing is needed.
 *
 * Intentionally does NOT extend AbstractEloquentRepository: there is no domain
 * entity to persist here — the write is a single raw INSERT...SELECT from a
 * database view. Precedent: ProductLookupTableProvider.
 *
 * DatabaseGateway (concrete, not the interface) is injected directly because
 * `runSql()` is exposed only on the concrete class, not on DatabaseGatewayInterface.
 */
final readonly class ProductPopularityRankingSnapshotRepository implements ProductPopularityRankingSnapshotRepositoryInterface
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

    /**
     * Assembles the full INSERT...SELECT SQL from the two focused sub-methods.
     * Explicit column list (not SELECT v.*) so a view column reorder cannot
     * silently misalign with the snapshot table.
     */
    private static function buildInsertSql(): string
    {
        return self::buildInsertHeader() . self::buildSelectFromView();
    }

    /**
     * INSERT INTO ... target column list.
     */
    private static function buildInsertHeader(): string
    {
        return <<<'SQL'
            INSERT INTO catalog.product_popularity_snapshots (
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
                trend
            )
            SQL;
    }

    /**
     * SELECT ... FROM catalog.product_popularity_ranking source column projection.
     */
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
                v.trend
            FROM catalog.product_popularity_ranking v
            SQL;
    }
}
