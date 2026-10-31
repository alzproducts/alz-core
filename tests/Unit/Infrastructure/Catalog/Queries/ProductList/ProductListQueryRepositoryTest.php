<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Infrastructure\Catalog\Queries\ProductList\ProductListQueryRepository;
use App\Infrastructure\Catalog\Queries\ProductList\ProductListSql;
use App\Infrastructure\Database\DatabaseGateway;
use Closure;
use Illuminate\Database\PostgresConnection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(ProductListQueryRepository::class)]
final class ProductListQueryRepositoryTest extends TestCase
{
    private const string HIT_ID = '00000000-0000-4000-8000-00000000000a';

    private const string FIRST_MISS_ID = '00000000-0000-4000-8000-00000000000b';

    private const string SECOND_MISS_ID = '00000000-0000-4000-8000-00000000000c';

    private DatabaseGateway&MockInterface $gateway;

    private PostgresConnection&MockInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Mockery::mock(
            PostgresConnection::class . '[select,statement,transactionLevel]',
            [static fn(): null => null, 'postgres', '', []],
        );
        $this->connection->shouldReceive('transactionLevel')->andReturn(0);
        $this->connection->shouldReceive('statement')->andReturn(true);
        $this->connection
            ->shouldReceive('select')
            ->withArgs(static fn(string $sql): bool => \str_starts_with($sql, 'select count(*)'))
            ->andReturn([(object) ['aggregate' => 3]]);

        $this->gateway = Mockery::mock(DatabaseGateway::class);
        $this->gateway->shouldReceive('connection')->andReturn($this->connection);
        $this->gateway->shouldReceive('transact')->andReturnUsing(static fn(Closure $operation): mixed => $operation());
    }

    #[Test]
    public function the_shared_inputs_are_read_once_for_a_page_with_misses_and_entries_keep_page_order(): void
    {
        $this->expectPageRows([
            self::missRow(self::FIRST_MISS_ID, 101),
            self::hitRow(self::HIT_ID),
            self::missRow(self::SECOND_MISS_ID, 102),
        ]);
        $this->connection
            ->shouldReceive('select')
            ->withArgs(static fn(string $sql): bool => $sql === ProductListSql::sharedInputsStatement())
            ->once()
            ->andReturn([(object) ['shared_inputs' => '{"custom_field_definitions":[]}']]);

        $page = $this->repository()->page(ProductListQueryParams::active(PageRequest::from(1, 3)));

        self::assertSame(3, $page->total);
        self::assertSame([self::FIRST_MISS_ID, self::HIT_ID, self::SECOND_MISS_ID], self::productIds($page->entries));
        self::assertSame([null, '{"id":"hit"}', null], \array_map(static fn(ProductListEntryDTO $entry): ?string => $entry->cachedBody, $page->entries));
        self::assertSame([101, null, 102], \array_map(static fn(ProductListEntryDTO $entry): ?int => $entry->view?->id->value, $page->entries));
    }

    #[Test]
    public function a_page_served_entirely_from_stored_fragments_never_reads_the_shared_inputs(): void
    {
        $this->expectPageRows([self::hitRow(self::SECOND_MISS_ID), self::hitRow(self::HIT_ID)]);
        $this->connection
            ->shouldReceive('select')
            ->withArgs(static fn(string $sql): bool => $sql === ProductListSql::sharedInputsStatement())
            ->never();

        $page = $this->repository()->page(ProductListQueryParams::active(PageRequest::from(1, 2)));

        self::assertSame([self::SECOND_MISS_ID, self::HIT_ID], self::productIds($page->entries));
    }

    /**
     * @param list<stdClass> $rows
     */
    private function expectPageRows(array $rows): void
    {
        $this->connection
            ->shouldReceive('select')
            ->withArgs(static fn(string $sql): bool => \str_starts_with($sql, 'WITH page AS MATERIALIZED'))
            ->once()
            ->andReturn($rows);
    }

    private function repository(): ProductListQueryRepository
    {
        return new ProductListQueryRepository($this->gateway, 'test-release');
    }

    /**
     * @param list<ProductListEntryDTO> $entries
     *
     * @return list<string>
     */
    private static function productIds(array $entries): array
    {
        return \array_map(static fn(ProductListEntryDTO $entry): string => $entry->productId, $entries);
    }

    private static function hitRow(string $productId): stdClass
    {
        return (object) ['product_id' => $productId, 'fingerprint' => \md5($productId), 'cached_body' => '{"id":"hit"}', 'inputs' => null];
    }

    private static function missRow(string $productId, int $externalId): stdClass
    {
        $inputs = [
            'id' => $productId, 'external_id' => $externalId, 'sku' => null, 'gtin' => null, 'title' => 'Product',
            'slug' => 'product', 'url' => 'https://shop.example/product', 'price' => 10, 'cost_price' => null,
            'sale_price' => null, 'rrp' => null, 'effective_price' => 10, 'is_on_sale' => false, 'has_any_sale' => false,
            'profit_margin' => null, 'is_active' => true, 'vat_exclusive' => false, 'vat_relief' => null,
            'meta_title' => null, 'meta_description' => null, 'category_ids' => [], 'main_category_ids' => [],
            'images' => [], 'custom_fields' => [], 'sort_order' => null, 'popularity_rank' => null,
            'popularity_max' => null, 'shopwired_created_at' => '2026-01-02T03:04:05+00:00',
            'shopwired_updated_at' => '2026-01-02T03:04:05+00:00', 'available_stock' => 0, 'physical_stock' => 0,
            'price_last_updated_at' => null, 'cost_price_last_updated_at' => null, 'stock_item' => null, 'variations' => [],
        ];

        return (object) [
            'product_id' => $productId,
            'fingerprint' => \md5($productId),
            'cached_body' => null,
            'inputs' => \json_encode($inputs, \JSON_THROW_ON_ERROR),
        ];
    }
}
