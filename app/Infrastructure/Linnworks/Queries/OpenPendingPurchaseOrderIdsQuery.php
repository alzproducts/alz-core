<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for OpenPendingPurchaseOrderIdsQuery results.
 *
 * @internal Implementation detail of OpenPendingPurchaseOrderIdsQuery
 */
final class OpenPendingPurchaseOrderIdsRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query OPEN and PENDING purchase order IDs via Linnworks Dashboards SQL API.
 *
 * Returns purchase order GUIDs for all OPEN or PENDING POs, optionally
 * filtered by the default location. Used for rapid polling to detect
 * status changes on active purchase orders.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class OpenPendingPurchaseOrderIdsQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private bool $defaultLocationOnly = false,
    ) {}

    protected function buildQueryBody(): string
    {
        $sql = "SELECT pkPurchaseID FROM [Purchase] WHERE (Status = 'OPEN' OR Status = 'PENDING')";

        if ($this->defaultLocationOnly) {
            $sql .= " AND fkLocationId = '00000000-0000-0000-0000-000000000000'";
        }

        return $sql . ' ORDER BY DateOfPurchase DESC';
    }

    /**
     * Map query results to list of purchase order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(OpenPendingPurchaseOrderIdsRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
