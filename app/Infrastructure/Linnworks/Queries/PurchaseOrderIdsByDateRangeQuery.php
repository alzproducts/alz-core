<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for PurchaseOrderIdsByDateRangeQuery results.
 *
 * @internal Implementation detail of PurchaseOrderIdsByDateRangeQuery
 */
final class PurchaseOrderIdsByDateRangeRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query purchase order IDs filtered by date range via Linnworks Dashboards SQL API.
 *
 * Returns all POs (any status, any warehouse) where either DateOfDelivery or
 * DateOfPurchase falls within the given range. Used for the normal daily sync
 * which fetches full PO data (3 API calls/PO).
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class PurchaseOrderIdsByDateRangeQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
    ) {}

    protected function buildQueryBody(): string
    {
        $from = SqlQueryBuilder::escapeString($this->from->format('Y-m-d H:i:s'));
        $to = SqlQueryBuilder::escapeString($this->to->format('Y-m-d H:i:s'));

        return 'SELECT pkPurchaseID FROM [Purchase]'
            . " WHERE (DateOfDelivery >= {$from} AND DateOfDelivery < {$to})"
            . " OR (DateOfPurchase >= {$from} AND DateOfPurchase < {$to})"
            . ' ORDER BY DateOfPurchase ASC';
    }

    /**
     * Map query results to list of purchase order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(PurchaseOrderIdsByDateRangeRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
