<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Enums\LinnworksLocation;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for FastPurchaseOrderIdsQuery results.
 *
 * @internal Implementation detail of FastPurchaseOrderIdsQuery
 */
final class FastPurchaseOrderIdsRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query purchase order IDs for fast sync via Linnworks Dashboards SQL API.
 *
 * Returns OPEN/PENDING/PARTIAL POs created since the given date plus,
 * optionally, DELIVERED POs with a delivery date of today. Filtered to
 * OurWarehouse (default location) only — this is the primary fulfillment
 * warehouse used for rapid polling.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class FastPurchaseOrderIdsQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private DateTimeImmutable $createdSince,
        private bool $includeDeliveredToday = true,
    ) {}

    protected function buildQueryBody(): string
    {
        $locationId = SqlQueryBuilder::escapeString(LinnworksLocation::Default->value);
        $createdSince = SqlQueryBuilder::escapeString($this->createdSince->format('Y-m-d H:i:s'));

        $openClause = "(Status IN ('OPEN', 'PENDING', 'PARTIAL') AND DateOfPurchase >= {$createdSince})";

        $sql = 'SELECT pkPurchaseID FROM [Purchase]'
            . " WHERE fkLocationId = {$locationId}"
            . " AND ({$openClause}";

        if ($this->includeDeliveredToday) {
            $sql .= " OR (Status = 'DELIVERED' AND CAST(DateOfDelivery AS DATE) = CAST(GETDATE() AS DATE))";
        }

        return $sql . ') ORDER BY DateOfPurchase ASC';
    }

    /**
     * Map query results to list of purchase order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(FastPurchaseOrderIdsRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
