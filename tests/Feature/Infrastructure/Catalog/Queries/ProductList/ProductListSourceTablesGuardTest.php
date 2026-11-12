<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Infrastructure\Catalog\Queries\ProductList\ProductListSql;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: the base tables behind the products list statement, resolved through every view it
 * reads (transitively, via information_schema.view_table_usage), are pinned.
 *
 * A stored list fragment is served while its fingerprint matches, and the fingerprint hashes only
 * the statement's `list_inputs`: a new source table whose data reaches the response without
 * reaching `list_inputs` would serve stale bodies silently.
 */
#[CoversClass(ProductListSql::class)]
final class ProductListSourceTablesGuardTest extends TestCase
{
    /** The fragment cache itself: read to serve bodies, never an input to them. */
    private const string FRAGMENT_TABLE = 'catalog.product_list_fragments';

    /** @var list<string> */
    private const array PINNED_SOURCE_TABLES = [
        'catalog.cost_price_changes',
        'catalog.free_delivery_shipping_costs',
        'catalog.product_extra_data',
        'catalog.product_popularity_ranking_config',
        'catalog.product_popularity_snapshots',
        'catalog.sku_popularity_ranking_config',
        'catalog.sku_popularity_snapshots',
        'linnworks.stock_item_suppliers',
        'linnworks.stock_items',
        'operations.price_periods',
        'shopwired.categories',
        'shopwired.custom_field_definitions',
        'shopwired.product_variations',
        'shopwired.products',
    ];

    #[Test]
    public function the_products_list_reads_exactly_the_pinned_source_tables(): void
    {
        $sources = $this->sourceTables($this->listStatementRelations());

        $added = \array_values(\array_diff($sources, self::PINNED_SOURCE_TABLES));
        $removed = \array_values(\array_diff(self::PINNED_SOURCE_TABLES, $sources));

        self::assertSame(
            [],
            $added,
            'New source table(s) feed the products list: ' . \implode(', ', $added)
            . '. Confirm the list fingerprint covers each (its data must reach ProductListSql list_inputs)'
            . ' before adding it to PINNED_SOURCE_TABLES.',
        );
        self::assertSame(
            [],
            $removed,
            'Pinned source table(s) no longer feed the products list: ' . \implode(', ', $removed)
            . '. Remove them from PINNED_SOURCE_TABLES.',
        );
    }

    /**
     * Schema-qualified relations named in the SQL the list read runs.
     *
     * @return list<string>
     */
    private function listStatementRelations(): array
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = $event->sql;
        });

        $this->app->make(ProductListReaderInterface::class)->page(new ProductListQueryParams(
            pagination: PageRequest::from(1, 1),
            filters: [ProductFilterField::Sku->value => 'GUARD-SOURCE-TABLES-NO-MATCH'],
        ));

        self::assertTrue(
            \array_any($statements, static fn(string $sql): bool => \str_starts_with($sql, 'WITH page AS MATERIALIZED')),
            'The list statement did not run',
        );

        \preg_match_all('/"?([a-z_][a-z0-9_]*)"?\."?([a-z_][a-z0-9_]*)"?/i', \implode("\n", $statements), $matches, \PREG_SET_ORDER);
        $names = \array_unique(\array_map(static fn(array $match): string => "{$match[1]}.{$match[2]}", $matches));

        return \array_values(\array_filter($names, static fn(string $name): bool => self::relkind($name) !== null));
    }

    /**
     * @param list<string> $relations
     *
     * @return list<string>
     */
    private function sourceTables(array $relations): array
    {
        $tables = [];
        $visited = [];
        $pending = $relations;

        while ($pending !== []) {
            $relation = \array_pop($pending);
            if (isset($visited[$relation])) {
                continue;
            }
            $visited[$relation] = true;

            $relkind = self::relkind($relation);
            if ($relkind === 'r' || $relkind === 'p') {
                $tables[] = $relation;

                continue;
            }

            self::assertSame('v', $relkind, "Cannot resolve the source tables of {$relation} (relkind {$relkind}): view_table_usage covers plain views only");
            \array_push($pending, ...self::viewUsage($relation));
        }

        $tables = \array_values(\array_diff($tables, [self::FRAGMENT_TABLE]));
        \sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function viewUsage(string $view): array
    {
        [$schema, $name] = \explode('.', $view, 2);

        return \array_map(
            static fn(object $row): string => $row->table_schema . '.' . $row->table_name,
            DB::select(
                'SELECT DISTINCT table_schema, table_name FROM information_schema.view_table_usage WHERE view_schema = ? AND view_name = ?',
                [$schema, $name],
            ),
        );
    }

    private static function relkind(string $relation): ?string
    {
        $row = DB::selectOne('SELECT c.relkind FROM pg_class c WHERE c.oid = to_regclass(?)', [$relation]);

        return \is_object($row) && \is_string($row->relkind) ? $row->relkind : null;
    }
}
