<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\UseCases;

use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Fixtures\QuickBooks\SpyOrderSyncRunRepository;
use Throwable;

#[CoversClass(HandleOrderSyncRunFailureUseCase::class)]
final class HandleOrderSyncRunFailureUseCaseTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, list<int>}>
     */
    public static function chunkFailures(): iterable
    {
        yield 'unfinished references only' => [new OrderChunkUnfinishedException([2]), [2]];
        yield 'anything else, the whole payload' => [new RuntimeException('test'), [1, 2, 3]];
    }

    /**
     * @param list<int> $references
     */
    #[Test]
    #[DataProvider('chunkFailures')]
    public function a_failed_chunk_is_logged_with_its_run_and_references(Throwable $failure, array $references): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('QuickBooks order chunk failed', [
            'run_id' => 'run-1',
            'references' => $references,
            'attempt' => 3,
            'exception' => $failure::class,
        ]);

        new HandleOrderSyncRunFailureUseCase(new SpyOrderSyncRunRepository(), $logger)->execute(self::chunkRun(), $failure);
    }

    #[Test]
    public function a_failed_range_is_logged_with_what_to_run_again(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('QuickBooks order range run failed', [
            'run_id' => 'run-1',
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'force_retry' => true,
            'exception' => RuntimeException::class,
        ]);

        new HandleOrderSyncRunFailureUseCase(new SpyOrderSyncRunRepository(), $logger)->execute(self::rangeRun(), new RuntimeException('test'));
    }

    /**
     * @return iterable<string, array{OrderRangeRunDTO|OrderChunkRunDTO}>
     */
    public static function failedJobs(): iterable
    {
        yield 'range' => [self::rangeRun()];
        yield 'chunk' => [self::chunkRun()];
    }

    #[Test]
    #[DataProvider('failedJobs')]
    public function a_failed_job_ends_failed_on_its_run_with_no_counts(OrderRangeRunDTO|OrderChunkRunDTO $run): void
    {
        $runs = new SpyOrderSyncRunRepository();

        new HandleOrderSyncRunFailureUseCase($runs, new NullLogger())->execute($run, new RuntimeException('test'));

        self::assertEquals([['endJob', 'run-1', OrderSyncRunCountsDTO::zero(), true]], $runs->calls);
    }

    #[Test]
    public function a_run_write_that_throws_is_logged_and_the_handler_returns(): void
    {
        $runs = new SpyOrderSyncRunRepository();
        $runs->throwOnEnd = new DatabaseOperationFailedException('update', 'test');
        $logger = $this->createMock(LoggerInterface::class);
        $errors = [];
        $logger->expects($this->exactly(2))->method('error')->willReturnCallback(
            static function (string $message, array $context) use (&$errors): void {
                $errors[] = [$message, $context];
            },
        );

        new HandleOrderSyncRunFailureUseCase($runs, $logger)->execute(self::chunkRun(), new RuntimeException('test'));

        self::assertSame(
            ['Ending a failed QuickBooks order sync run failed', ['run_id' => 'run-1', 'exception' => DatabaseOperationFailedException::class]],
            $errors[1],
        );
    }

    private static function chunkRun(): OrderChunkRunDTO
    {
        return new OrderChunkRunDTO(new OrderSyncChunkDTO('realm-1', [1, 2, 3], false, OrderSyncTrigger::Range, 'run-1'), 3);
    }

    private static function rangeRun(): OrderRangeRunDTO
    {
        return new OrderRangeRunDTO('realm-1', new DateRange(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-31')), true, 'run-1');
    }
}
