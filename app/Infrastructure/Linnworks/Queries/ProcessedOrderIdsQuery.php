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
 * Row structure for ProcessedOrderIdsQuery results.
 *
 * @internal Implementation detail of ProcessedOrderIdsQuery
 */
final class ProcessedOrderIdsRow extends Data
{
    public function __construct(
        #[MapInputName('pkOrderID')]
        public readonly string $orderId,
    ) {}
}

/**
 * Query processed order IDs via Linnworks Dashboards SQL API.
 *
 * Returns all processed order GUIDs, optionally filtered by date range.
 * Used for historical backfill where the v2 GetOrders API's ~30-day
 * fromDate limit prevents reaching older orders.
 *
 * Note: "dReceievedDate" is Linnworks' actual column name (typo in their schema).
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class ProcessedOrderIdsQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private ?DateTimeImmutable $from = null,
        private ?DateTimeImmutable $to = null,
    ) {}

    protected function buildQueryBody(): string
    {
        $sql = "SELECT pkOrderID FROM [Order] WHERE bProcessed = 'TRUE'";

        if ($this->from !== null) {
            $fromEscaped = SqlQueryBuilder::escapeString($this->from->format('Y-m-d H:i:s'));
            $sql .= " AND dReceievedDate >= {$fromEscaped}";
        }

        if ($this->to !== null) {
            $toEscaped = SqlQueryBuilder::escapeString($this->to->format('Y-m-d H:i:s'));
            $sql .= " AND dReceievedDate < {$toEscaped}";
        }

        return $sql . ' ORDER BY dReceievedDate ASC';
    }

    /**
     * Map query results to list of order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(ProcessedOrderIdsRow::from($row)->orderId),
            $response->results,
        );
    }
}
