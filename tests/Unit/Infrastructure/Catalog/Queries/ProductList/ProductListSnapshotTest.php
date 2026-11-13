<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\Queries\ProductList;

use App\Infrastructure\Catalog\Queries\ProductList\ProductListSnapshot;
use App\Infrastructure\Database\DatabaseGateway;
use Closure;
use Illuminate\Database\Connection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductListSnapshot::class)]
final class ProductListSnapshotTest extends TestCase
{
    private DatabaseGateway&MockInterface $gateway;

    private Connection&MockInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(DatabaseGateway::class);
        $this->connection = Mockery::mock(Connection::class);
        $this->gateway->shouldReceive('connection')->andReturn($this->connection);
    }

    #[Test]
    public function outside_a_transaction_the_reads_run_in_a_repeatable_read_read_only_transaction(): void
    {
        $order = [];
        $this->connection->shouldReceive('transactionLevel')->andReturn(0);
        $this->connection
            ->shouldReceive('statement')
            ->once()
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')
            ->andReturnUsing(static function () use (&$order): bool {
                $order[] = 'set';

                return true;
            });
        $this->gateway->shouldReceive('transact')->once()->andReturnUsing(static fn(Closure $operation): mixed => $operation());
        $this->gateway->shouldNotReceive('query');

        $result = ProductListSnapshot::read($this->gateway, static function () use (&$order): string {
            $order[] = 'reads';

            return 'page';
        });

        self::assertSame('page', $result);
        self::assertSame(['set', 'reads'], $order);
    }

    #[Test]
    public function inside_an_open_transaction_the_reads_join_it_without_setting_the_isolation_level(): void
    {
        $this->connection->shouldReceive('transactionLevel')->andReturn(1);
        $this->connection->shouldNotReceive('statement');
        $this->gateway->shouldNotReceive('transact');
        $this->gateway->shouldReceive('query')->once()->andReturnUsing(static fn(Closure $operation): mixed => $operation());

        self::assertSame('page', ProductListSnapshot::read($this->gateway, static fn(): string => 'page'));
    }
}
