<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\BulkCostPriceUpdate;

use App\Application\Contracts\Linnworks\CostPriceUpdateDispatcherInterface;
use App\Application\Linnworks\BulkCostPriceUpdate\DispatchBulkCostPriceJobsUseCase;
use App\Application\Linnworks\BulkCostPriceUpdate\SupplierCostPriceBatchDTO;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(DispatchBulkCostPriceJobsUseCase::class)]
final class DispatchBulkCostPriceJobsUseCaseTest extends TestCase
{
    private CostPriceUpdateDispatcherInterface&MockInterface $dispatcher;

    private DispatchBulkCostPriceJobsUseCase $useCase;

    /** @var list<array{string, int}> Supplier + chunk size for each dispatch, in order */
    private array $dispatched = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = Mockery::mock(CostPriceUpdateDispatcherInterface::class);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();

        /** @param list<UpdateCostPriceCommand> $commands */
        $record = function (string $supplier, array $commands): void {
            $this->dispatched[] = [$supplier, \count($commands)];
        };
        $this->dispatcher->shouldReceive('dispatchCostPriceBatch')->andReturnUsing($record);

        $this->useCase = new DispatchBulkCostPriceJobsUseCase($this->dispatcher, $logger);
    }

    #[Test]
    public function it_chunks_one_supplier_into_jobs_of_at_most_100(): void
    {
        $result = $this->useCase->execute([new SupplierCostPriceBatchDTO('AcmeCo', $this->makeCommands(250))]);

        self::assertSame([['AcmeCo', 100], ['AcmeCo', 100], ['AcmeCo', 50]], $this->dispatched);
        self::assertSame(1, $result->supplierCount);
        self::assertSame(250, $result->skuCount);
        self::assertSame(3, $result->jobsDispatched);
    }

    #[Test]
    public function it_chunks_each_supplier_independently(): void
    {
        $result = $this->useCase->execute([
            new SupplierCostPriceBatchDTO('AcmeCo', $this->makeCommands(150)),
            new SupplierCostPriceBatchDTO('GlobalParts', $this->makeCommands(50)),
        ]);

        self::assertSame([['AcmeCo', 100], ['AcmeCo', 50], ['GlobalParts', 50]], $this->dispatched);
        self::assertSame(2, $result->supplierCount);
        self::assertSame(200, $result->skuCount);
        self::assertSame(3, $result->jobsDispatched);
    }

    /**
     * @return list<UpdateCostPriceCommand>
     */
    private function makeCommands(int $count): array
    {
        $commands = [];
        for ($i = 1; $i <= $count; $i++) {
            $commands[] = new UpdateCostPriceCommand(Sku::fromTrusted("SKU-{$i}"), Money::exclusive(1.0));
        }

        return $commands;
    }
}
