<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Application\Catalog\Commands\CostPriceChangeCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Catalog\Repositories\EloquentCostPriceChangeLogRepository;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Linnworks\Repositories\EloquentStockItemSupplierRepository;
use RuntimeException;

/**
 * A cost-price update on a fixed sample of the main supplier's default SKUs: the bulk purchase-price UPDATE plus its
 * change-log rows, alternating each price between its original and one penny more.
 */
final class CostPriceWriter
{
    private const string SAMPLE_SQL = <<<'SQL'
        SELECT si.item_number AS sku, s.supplier_id, s.supplier_name, s.purchase_price::float8 AS purchase_price
        FROM linnworks.stock_item_suppliers AS s
        JOIN linnworks.stock_items AS si ON si.stock_item_id = s.stock_item_id
        WHERE s.is_default AND s.purchase_price IS NOT NULL AND si.item_number IS NOT NULL
          AND s.supplier_name = (
              SELECT supplier_name FROM linnworks.stock_item_suppliers
              WHERE is_default AND purchase_price IS NOT NULL
              GROUP BY supplier_name ORDER BY count(*) DESC, supplier_name LIMIT 1
          )
        ORDER BY si.item_number
        LIMIT 25
        SQL;

    /** @var list<object{sku: string, supplier_id: string, supplier_name: string, purchase_price: float}> */
    private array $sample = [];

    private bool $bumped = false;

    private string $startedAt = '';

    public function __construct(
        private readonly EloquentStockItemSupplierRepository $suppliers,
        private readonly EloquentCostPriceChangeLogRepository $changeLog,
        private readonly DatabaseGateway $database,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function captureBaseline(): void
    {
        /** @var list<object{sku: string, supplier_id: string, supplier_name: string, purchase_price: float}> $sample */
        $sample = $this->database->query(fn(): array => $this->database->connection()->select(self::SAMPLE_SQL));
        $clock = $this->database->query(fn(): mixed => $this->database->connection()->scalar('SELECT clock_timestamp()::text'));

        $this->sample = $sample;
        $this->startedAt = \is_string($clock) ? $clock : throw new RuntimeException('Could not read the database clock.');
        $this->bumped = false;
    }

    /**
     * @throws RuntimeException
     */
    public function update(): void
    {
        $this->bumped = ! $this->bumped;
        $changes = [];
        $prices = [];

        foreach ($this->sample as $row) {
            $original = $row->purchase_price;
            $prices[$row->sku] = $this->bumped ? \round($original + 0.01, 4) : $original;
            $changes[] = new CostPriceChangeCommand(
                sku: Sku::fromTrusted($row->sku),
                supplierId: Guid::fromTrusted($row->supplier_id),
                supplierName: $row->supplier_name,
                oldPrice: Money::exclusive($this->bumped ? $original : \round($original + 0.01, 4)),
                newPrice: Money::exclusive($prices[$row->sku]),
            );
        }

        $this->writePrices($prices);
        $this->changeLog->record($changes);
    }

    /**
     * Puts the original prices back and deletes the change-log rows this run inserted.
     *
     * @throws RuntimeException
     */
    public function restore(): void
    {
        $original = [];

        foreach ($this->sample as $row) {
            $original[$row->sku] = $row->purchase_price;
        }

        $this->writePrices($original);
        $this->bumped = false;

        if ($original !== []) {
            $placeholders = \implode(', ', \array_fill(0, \count($original), '?'));
            $this->database->runSql(
                "DELETE FROM catalog.cost_price_changes WHERE changed_at >= ?::timestamptz AND sku IN ({$placeholders})",
                [$this->startedAt, ...\array_map('strval', \array_keys($original))],
            );
        }
    }

    /**
     * @param array<string, float> $prices
     *
     * @throws RuntimeException
     */
    private function writePrices(array $prices): void
    {
        if ($this->sample !== []) {
            $this->suppliers->bulkUpdatePurchasePrices($this->sample[0]->supplier_name, $prices);
        }
    }
}
