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
 * Row structure for PurchaseOrderIdsByDateQuery results.
 *
 * @internal Implementation detail of PurchaseOrderIdsByDateQuery
 */
final class PurchaseOrderIdsByDateRow extends Data
{
    public function __construct(
        #[MapInputName('pkPurchaseID')]
        public readonly string $purchaseId,
    ) {}
}

/**
 * Query purchase order IDs via Linnworks Dashboards SQL API, filtered by date.
 *
 * Returns purchase order GUIDs optionally filtered by DateOfPurchase range
 * and/or default location. Used for historical backfill.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class PurchaseOrderIdsByDateQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private ?DateTimeImmutable $from = null,
        private ?DateTimeImmutable $to = null,
        private bool $defaultLocationOnly = false,
    ) {}

    protected function buildQueryBody(): string
    {
        $sql = 'SELECT pkPurchaseID FROM [Purchase]';
        $conditions = [];

        if ($this->from !== null) {
            $fromEscaped = SqlQueryBuilder::escapeString($this->from->format('Y-m-d H:i:s'));
            $conditions[] = "DateOfPurchase >= {$fromEscaped}";
        }

        if ($this->to !== null) {
            $toEscaped = SqlQueryBuilder::escapeString($this->to->format('Y-m-d H:i:s'));
            $conditions[] = "DateOfPurchase < {$toEscaped}";
        }

        if ($this->defaultLocationOnly) {
            $conditions[] = "fkLocationId = '00000000-0000-0000-0000-000000000000'";
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . \implode(' AND ', $conditions);
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
            static fn(array $row): Guid => new Guid(PurchaseOrderIdsByDateRow::from($row)->purchaseId),
            $response->results,
        );
    }
}
