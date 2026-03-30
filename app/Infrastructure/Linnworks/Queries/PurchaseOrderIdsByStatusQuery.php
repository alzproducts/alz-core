<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\Enums\WarehouseScope;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Enums\LinnworksLocation;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for PurchaseOrderIdsByStatusQuery results.
 *
 * @internal Implementation detail of PurchaseOrderIdsByStatusQuery
 */
final class PurchaseOrderIdsByStatusRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query purchase order IDs filtered by status via Linnworks Dashboards SQL API.
 *
 * Status is required. Warehouse scope and date range are optional.
 * WarehouseScope is converted to SQL using the default location GUID:
 * - OurWarehouse: fkLocationId = DEFAULT
 * - ExcludingOurWarehouse: fkLocationId != DEFAULT
 * - AnyWarehouse: no location filter
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class PurchaseOrderIdsByStatusQuery extends AbstractLinnworksQuery
{
    /**
     * @param list<PurchaseOrderStatus> $statuses At least one status required
     */
    public function __construct(
        private array $statuses,
        private WarehouseScope $warehouseScope = WarehouseScope::AnyWarehouse,
        private ?DateTimeImmutable $from = null,
        private ?DateTimeImmutable $to = null,
    ) {}

    protected function buildQueryBody(): string
    {
        $statusClause = 'Status IN ' . SqlQueryBuilder::buildInClause(
            \array_map(static fn(PurchaseOrderStatus $s): string => $s->value, $this->statuses),
        );

        $sql = "SELECT pkPurchaseID FROM [Purchase] WHERE {$statusClause}";

        $sql .= match ($this->warehouseScope) {
            WarehouseScope::OurWarehouse => ' AND fkLocationId = ' . SqlQueryBuilder::escapeString(LinnworksLocation::Default->value),
            WarehouseScope::ExcludingOurWarehouse => ' AND fkLocationId != ' . SqlQueryBuilder::escapeString(LinnworksLocation::Default->value),
            WarehouseScope::AnyWarehouse => '',
        };

        if ($this->from !== null) {
            $fromEscaped = SqlQueryBuilder::escapeString($this->from->format('Y-m-d H:i:s'));
            $sql .= " AND DateOfPurchase >= {$fromEscaped}";
        }

        if ($this->to !== null) {
            $toEscaped = SqlQueryBuilder::escapeString($this->to->format('Y-m-d H:i:s'));
            $sql .= " AND DateOfPurchase < {$toEscaped}";
        }

        return $sql . ' ORDER BY DateOfPurchase ASC';
    }

    /**
     * Map query results to list of purchase order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(PurchaseOrderIdsByStatusRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
