<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for AllPurchaseOrderIdsQuery results.
 *
 * @internal Implementation detail of AllPurchaseOrderIdsQuery
 */
final class AllPurchaseOrderIdsRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query all purchase order IDs via Linnworks Dashboards SQL API.
 *
 * Returns every PO ID with no filters — all statuses, all warehouses.
 * Used for manual full backfill via BackfillPurchaseOrdersCommand or
 * SyncAllPurchaseOrdersJob. Ordered by DateOfPurchase for predictable processing.
 *
 * Note: Loads all IDs into memory at once. Acceptable for manual backfill
 * (infrequent, operator-initiated), where full consistency takes priority
 * over memory efficiency.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class AllPurchaseOrderIdsQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return 'SELECT pkPurchaseID FROM [Purchase] ORDER BY DateOfPurchase ASC';
    }

    /**
     * Map query results to list of purchase order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(AllPurchaseOrderIdsRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
