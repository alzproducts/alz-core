<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Infrastructure\Database\DatabaseGateway;
use RuntimeException;

/**
 * Every row of the tables the bench writers rewrite, held in memory and written back verbatim and in scan order.
 * Saving a product re-inserts its variations with fresh UUIDs and timestamps, and the variation readers have no
 * ORDER BY: their order follows the plan's scan of these heaps (the supplier seq scan included), so the restore must
 * reproduce the physical row order as well as the values.
 */
final class CatalogueRowsSnapshot
{
    /** Parents before children, for the re-inserts */
    private const array TABLES = [
        'linnworks.stock_items',
        'linnworks.stock_item_extended_properties',
        'linnworks.stock_item_suppliers',
        'shopwired.categories',
        'shopwired.products',
        'shopwired.product_variations',
    ];

    /** @var array<string, string> Table => its rows as a JSON array, in heap order */
    private array $rows = [];

    public function __construct(
        private readonly DatabaseGateway $database,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function capture(): void
    {
        $this->rows = [];

        foreach (self::TABLES as $table) {
            $value = $this->database->query(
                fn(): mixed => $this->database->connection()->scalar("SELECT COALESCE(jsonb_agg(t), '[]'::jsonb)::text FROM {$table} AS t"),
            );
            $this->rows[$table] = \is_string($value) ? $value : throw new RuntimeException('Could not read the catalogue rows snapshot.');
        }
    }

    /**
     * @throws RuntimeException
     */
    public function restore(): void
    {
        if ($this->rows === []) {
            return;
        }

        // intentional: TRUNCATE, not DELETE, so the re-inserts append in captured order instead of refilling freed pages;
        // ANALYZE because TRUNCATE resets the planner statistics, and a different plan scans the heaps in another order.
        $this->database->transact(function (): void {
            $connection = $this->database->connection();
            $tables = \implode(', ', \array_keys($this->rows));
            $connection->statement("TRUNCATE {$tables}");

            foreach ($this->rows as $table => $json) {
                $connection->insert("INSERT INTO {$table} SELECT * FROM jsonb_populate_recordset(NULL::{$table}, ?::jsonb)", [$json]);
            }

            $connection->statement("ANALYZE {$tables}");
        });
    }
}
