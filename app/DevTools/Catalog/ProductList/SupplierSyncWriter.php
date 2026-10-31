<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use RuntimeException;

/**
 * One page of the Linnworks stock-item and supplier sync, saved back from the stock items the database already holds:
 * the stock item upsert plus its extended-property and supplier delete and re-insert. Pages rotate through the items.
 */
final class SupplierSyncWriter
{
    /** The sync's page size */
    private const int PAGE_SIZE = 200;

    /** @var list<list<string>> Pages of stock item row ids, in stock_item_id order */
    private array $pages = [];

    private int $nextPage = 0;

    public function __construct(
        private readonly StockItemRepositoryInterface $stockItems,
        private readonly DatabaseGateway $database,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function captureBaseline(): void
    {
        /** @var list<object{id: string}> $rows */
        $rows = $this->database->query(fn(): array => $this->database->connection()->select('SELECT id FROM linnworks.stock_items ORDER BY stock_item_id'));

        $this->pages = \array_chunk(\array_map(static fn(object $row): string => $row->id, $rows), self::PAGE_SIZE);
        $this->nextPage = 0;
    }

    /**
     * @throws RuntimeException
     */
    public function sync(): void
    {
        $page = $this->pages === [] ? [] : ($this->pages[$this->nextPage % \count($this->pages)] ?? []);
        $this->nextPage++;

        if ($page === []) {
            return;
        }

        $this->stockItems->saveMany(\array_values(StockItemModel::query()
            ->with(['suppliers', 'extendedProperties'])
            ->findMany($page)
            ->sortBy('stock_item_id')
            ->map(static fn(StockItemModel $model): StockItemFull => $model->toDomain())
            ->all()));
    }
}
