<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderRangeSyncUseCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fixtures\QuickBooks\SpyOrderSyncDispatcher;

#[CoversClass(RunOrderRangeSyncUseCase::class)]
final class RunOrderRangeSyncUseCaseTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, list<int>}>
     */
    public static function ranges(): iterable
    {
        yield 'none left' => [[], []];
        yield 'one full chunk' => [\range(1, 20), [20]];
        yield 'one over a chunk' => [\range(1, 21), [20, 1]];
        yield 'three chunks' => [\range(1, 45), [20, 20, 5]];
    }

    /**
     * @param list<int> $references
     * @param list<int> $sizes
     */
    #[Test]
    #[DataProvider('ranges')]
    public function every_reference_in_the_range_is_dispatched_in_chunks_of_at_most_20(array $references, array $sizes): void
    {
        $sourceOrders = $this->createMock(OrderSyncSourceQueryRepositoryInterface::class);
        $sourceOrders->expects($this->once())->method('referencesPlacedIn')
            ->with(new DateTimeImmutable('2026-07-14T23:00:00Z'), new DateTimeImmutable('2026-07-16T23:00:00Z'))
            ->willReturn($references);
        $dispatcher = new SpyOrderSyncDispatcher();

        new RunOrderRangeSyncUseCase($sourceOrders, $dispatcher, new NullLogger())->execute(
            new OrderRangeRunDTO('realm-1', new DateTimeImmutable('2026-07-15'), new DateTimeImmutable('2026-07-16'), true, 'run-1'),
        );

        self::assertSame($sizes, \array_map(static fn(OrderChunkRunDTO $run): int => \count($run->chunk->references), $dispatcher->chunks));
        self::assertSame($references, \array_merge(...\array_map(static fn(OrderChunkRunDTO $run): array => $run->chunk->references, $dispatcher->chunks)));
        foreach ($dispatcher->chunks as $run) {
            self::assertEquals(new OrderChunkRunDTO(new OrderSyncChunkDTO('realm-1', $run->chunk->references, true, OrderSyncTrigger::Range, 'run-1'), 1), $run);
        }
        self::assertSame([], $dispatcher->ranges);
    }
}
