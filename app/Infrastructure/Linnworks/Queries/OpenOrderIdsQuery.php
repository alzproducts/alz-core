<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for OpenOrderIdsQuery results.
 *
 * @internal Implementation detail of OpenOrderIdsQuery
 */
final class OpenOrderIdsRow extends Data
{
    public function __construct(
        #[MapInputName('pkOrderID')]
        public readonly string $orderId,
    ) {}
}

/**
 * Query all open order IDs via Linnworks Dashboards SQL API.
 *
 * Returns GUIDs for all orders currently in the Open_Order view
 * (pending/unpaid orders not yet processed). No date filter needed —
 * the open order set is small enough to sync in full on every run.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class OpenOrderIdsQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return 'SELECT pkOrderID FROM [Open_Order]';
    }

    /**
     * Map query results to list of order GUIDs.
     *
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => new Guid(OpenOrderIdsRow::from($row)->orderId),
            $response->results,
        );
    }
}
