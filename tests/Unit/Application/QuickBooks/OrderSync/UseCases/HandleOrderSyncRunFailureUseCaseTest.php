<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\UseCases;

use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
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

        new HandleOrderSyncRunFailureUseCase($logger)->execute(
            new OrderChunkRunDTO(new OrderSyncChunkDTO('realm-1', [1, 2, 3], false, OrderSyncTrigger::Range, 'run-1'), 3),
            $failure,
        );
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

        new HandleOrderSyncRunFailureUseCase($logger)->execute(
            new OrderRangeRunDTO('realm-1', new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-31'), true, 'run-1'),
            new RuntimeException('test'),
        );
    }
}
