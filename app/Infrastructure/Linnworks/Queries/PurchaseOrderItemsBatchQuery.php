<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Row structure for PurchaseOrderItemsBatchQuery results.
 *
 * @internal Implementation detail of PurchaseOrderItemsBatchQuery
 */
final class PurchaseOrderItemsBatchRow extends Data
{
    public function __construct(
        public readonly string $pkPurchaseItemId,
        public readonly string $fkPurchasId,
        public readonly string $fkStockItemId,
        public readonly string $Quantity,
        public readonly string $Cost,
        public readonly string $Delivered,
        public readonly string $TaxRate,
        public readonly string $Tax,
        public readonly string $PackQuantity,
        public readonly string $PackSize,
        public readonly string $SortOrder,
    ) {}
}

/**
 * Batch-fetch purchase order items grouped by parent purchase ID.
 *
 * Note: The Linnworks DB has a known typo — the FK column is `fkPurchasId`
 * (missing 'e'), not `fkPurchaseId`.
 *
 * @extends AbstractLinnworksQuery<array<string, list<PurchaseOrderItem>>>
 *
 * @template-pattern Query Object
 */
final readonly class PurchaseOrderItemsBatchQuery extends AbstractLinnworksQuery
{
    /**
     * @param list<Guid> $purchaseIds
     *
     * @throws InvalidArgumentException When purchase IDs are empty
     */
    public function __construct(
        private array $purchaseIds,
    ) {
        if ($this->purchaseIds === []) {
            throw new InvalidArgumentException('Purchase IDs cannot be empty');
        }
    }

    protected function buildQueryBody(): string
    {
        $inClause = SqlQueryBuilder::buildGuidInClause($this->purchaseIds);

        return <<<SQL
            SELECT pkPurchaseItemId, fkPurchasId, fkStockItemId,
                Quantity, Cost, Delivered, TaxRate, Tax,
                PackQuantity, PackSize, SortOrder
            FROM [PurchaseItem]
            WHERE fkPurchasId IN {$inClause}
            SQL;
    }

    /**
     * Map query results to items grouped by purchase ID.
     *
     * @return array<string, list<PurchaseOrderItem>>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        /** @var array<string, list<PurchaseOrderItem>> $grouped */
        $grouped = [];

        foreach ($response->results as $row) {
            $parsed = PurchaseOrderItemsBatchRow::from($row);
            $grouped[$parsed->fkPurchasId][] = self::mapRowToItem($parsed);
        }

        return $grouped;
    }

    private static function mapRowToItem(PurchaseOrderItemsBatchRow $row): PurchaseOrderItem
    {
        // Linnworks uses -1 to signal "tax rate not set".
        $taxRateValue = (float) $row->TaxRate;

        return new PurchaseOrderItem(
            pkPurchaseItemId: Guid::fromTrusted($row->pkPurchaseItemId),
            fkStockItemId: Guid::fromTrusted($row->fkStockItemId),
            quantity: (int) $row->Quantity,
            delivered: (int) $row->Delivered,
            packQuantity: (int) $row->PackQuantity,
            packSize: (int) $row->PackSize,
            cost: (float) $row->Cost,
            tax: (float) $row->Tax,
            taxRate: $taxRateValue < 0 ? null : TaxRate::fromPercentage($taxRateValue),
            sortOrder: (int) $row->SortOrder,
        );
    }
}
