<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Catalog\Queries\ProductList\PostgresProductListFragmentStore;
use App\Infrastructure\Database\DatabaseGateway;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PostgresProductListFragmentStore::class)]
final class PostgresProductListFragmentStoreTest extends TestCase
{
    private const string PRODUCT_A = '1a8f3c1e-5b7d-4e2a-9c6f-1d3b5a7e9f21';

    private const string PRODUCT_B = '2b8f3c1e-5b7d-4e2a-9c6f-1d3b5a7e9f21';

    private DatabaseGateway&MockInterface $gateway;

    private ErrorReporterInterface&MockInterface $errorReporter;

    private PostgresProductListFragmentStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(DatabaseGateway::class);
        $this->errorReporter = Mockery::mock(ErrorReporterInterface::class);
        $this->store = new PostgresProductListFragmentStore($this->gateway, $this->errorReporter);
    }

    #[Test]
    public function store_many_upserts_one_row_per_key_in_key_order_with_the_last_fragment_winning(): void
    {
        $this->gateway
            ->shouldReceive('runSql')
            ->once()
            ->with(
                Mockery::on(static fn(string $sql): bool => \mb_substr_count($sql, '(?::uuid, ?, ?, ?, now())') === 2
                    && \str_contains($sql, 'ON CONFLICT (product_id, shape) DO UPDATE')),
                [self::PRODUCT_A, 'stock', 'fp-a', '{"a":2}', self::PRODUCT_B, 'none', 'fp-b', '{"b":1}'],
            )
            ->andReturn(2);
        $this->errorReporter->shouldNotReceive('report');

        $this->store->storeMany([
            new ProductListFragmentDTO(self::PRODUCT_B, 'none', 'fp-b', '{"b":1}'),
            new ProductListFragmentDTO(self::PRODUCT_A, 'stock', 'fp-old', '{"a":1}'),
            new ProductListFragmentDTO(self::PRODUCT_A, 'stock', 'fp-a', '{"a":2}'),
        ]);
    }

    #[Test]
    public function store_many_with_no_fragments_writes_nothing(): void
    {
        $this->gateway->shouldNotReceive('runSql');

        $this->store->storeMany([]);
    }

    #[Test]
    public function a_failed_write_is_reported_and_does_not_fail_the_request(): void
    {
        $failure = new DatabaseOperationFailedException('runSql', 'connection reset');
        $this->gateway->shouldReceive('runSql')->once()->andThrow($failure);
        $this->errorReporter
            ->shouldReceive('report')
            ->once()
            ->with($failure, ['operation' => 'store_product_list_fragments', 'fragments' => 1]);

        $this->store->storeMany([new ProductListFragmentDTO(self::PRODUCT_A, 'none', 'fp-a', '{"a":1}')]);
    }
}
