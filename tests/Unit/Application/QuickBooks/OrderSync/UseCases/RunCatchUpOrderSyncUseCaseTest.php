<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncRunQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\CatchUpCandidateWindowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRunStatus;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Results\CatchUpOrderSyncResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreflightService;
use App\Application\QuickBooks\OrderSync\UseCases\RunCatchUpOrderSyncUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\QuickBooks\SpyOrderSyncDispatcher;
use Tests\Fixtures\QuickBooks\SpyOrderSyncRunRepository;
use Tests\Unit\Infrastructure\QuickBooks\RecordingLogger;
use Throwable;

#[CoversClass(RunCatchUpOrderSyncUseCase::class)]
#[CoversClass(CatchUpOrderSyncResult::class)]
final class RunCatchUpOrderSyncUseCaseTest extends TestCase
{
    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const string NOW = '2031-07-15T12:00:00+00:00';

    private ?DateTimeImmutable $floor;

    private ?DateTimeImmutable $scheduleFrom;

    private ?InvalidConfigurationException $settingsThrow = null;

    private ?OrderSyncRunDTO $latest = null;

    /** @var list<MappingKey> */
    private array $unresolved = [];

    /** @var list<int> */
    private array $candidates;

    private ?CatchUpCandidateWindowDTO $window = null;

    private QuickBooksTokenService&Stub $tokens;

    private SpyOrderSyncRunRepository $runs;

    private SpyOrderSyncDispatcher $dispatcher;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->floor = new DateTimeImmutable('2031-07-01');
        $this->scheduleFrom = new DateTimeImmutable('2031-07-11');
        $this->candidates = [101, 102, 103];
        $this->tokens = $this->createStub(QuickBooksTokenService::class);
        $this->tokens->method('accessToken')->willReturn(new ApiKeyToken('test-access-token'));
        $this->runs = new SpyOrderSyncRunRepository();
        $this->dispatcher = new SpyOrderSyncDispatcher();
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function the_candidates_are_queued_as_one_schedule_run_held_open_until_every_chunk_is_queued(): void
    {
        $this->candidates = \range(1, 45);

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::queued(self::RUN_ID, 45, 3), $result);
        self::assertSame([20, 20, 5], \array_map(static fn(OrderChunkRunDTO $run): int => \count($run->chunk->references), $this->dispatcher->chunks));
        self::assertSame($this->candidates, \array_merge(...\array_map(static fn(OrderChunkRunDTO $run): array => $run->chunk->references, $this->dispatcher->chunks)));
        foreach ($this->dispatcher->chunks as $run) {
            self::assertEquals(new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $run->chunk->references, false, OrderSyncTrigger::Schedule, self::RUN_ID), 1), $run);
        }
        self::assertEquals([
            ['record', self::RUN_ID, new RequestedOrderSyncRunDTO(self::RUN_ID, OrderSyncTrigger::Schedule, false, null, null, 45, new DateTimeImmutable(self::NOW))],
            ['addOpenJob', self::RUN_ID],
            ['endJob', self::RUN_ID, OrderSyncRunCountsDTO::zero(), false],
        ], $this->runs->calls);
        self::assertSame(['info', 'info'], $this->logger->levels);
        self::assertSame(['Queued QuickBooks order catch-up chunks', ['run_id' => self::RUN_ID, 'candidate_count' => 45, 'chunks' => 3]], $this->logger->records[1]);
    }

    #[Test]
    public function the_window_runs_from_the_start_date_to_one_hour_before_the_handle_retaking_item_misses_for_48_hours(): void
    {
        $this->execute();

        self::assertEquals(new CatchUpCandidateWindowDTO(
            self::REALM,
            new DateTimeImmutable('2031-07-10T23:00:00+00:00'),
            new DateTimeImmutable('2031-07-15T11:00:00+00:00'),
            new DateTimeImmutable('2031-07-13T12:00:00+00:00'),
        ), $this->window);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function startDates(): iterable
    {
        yield 'schedule start after the floor' => ['2031-07-01', '2031-07-11', '2031-07-10T23:00:00+00:00'];
        yield 'floor after the schedule start' => ['2031-07-12', '2031-07-02', '2031-07-11T23:00:00+00:00'];
        yield 'both on one day' => ['2031-01-05', '2031-01-05', '2031-01-05T00:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('startDates')]
    public function the_start_date_is_the_later_of_the_floor_and_the_schedule_start_from_london_midnight(string $floor, string $scheduleFrom, string $start): void
    {
        $this->floor = new DateTimeImmutable($floor);
        $this->scheduleFrom = new DateTimeImmutable($scheduleFrom);

        $this->execute();

        self::assertEquals(new DateTimeImmutable($start), $this->window?->from);
    }

    #[Test]
    public function an_unset_schedule_start_skips_at_info_before_anything_else_is_read(): void
    {
        $this->scheduleFrom = null;
        $this->floor = null;

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::skipped(CatchUpOrderSyncResult::SKIPPED_SWITCHED_OFF), $result);
        $this->assertNothingWritten(['info', 'info'], 'switched_off');
        self::assertNull($this->window);
    }

    #[Test]
    public function no_candidates_skips_at_info_and_writes_no_run(): void
    {
        $this->candidates = [];

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::skipped(CatchUpOrderSyncResult::SKIPPED_NO_CANDIDATES), $result);
        $this->assertNothingWritten(['info', 'info'], 'no_candidates');
    }

    /**
     * @return iterable<string, array{OrderSyncRunStatus, string}>
     */
    public static function blockingRuns(): iterable
    {
        yield 'queued an hour ago' => [OrderSyncRunStatus::Queued, '2031-07-15T11:00:00+00:00'];
        yield 'running a minute short of a day' => [OrderSyncRunStatus::Running, '2031-07-14T12:01:00+00:00'];
    }

    #[Test]
    #[DataProvider('blockingRuns')]
    public function an_open_schedule_run_under_24_hours_old_skips_at_info(OrderSyncRunStatus $status, string $requestedAt): void
    {
        $this->latest = self::latestRun($status, $requestedAt);

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::skipped(CatchUpOrderSyncResult::SKIPPED_PREVIOUS_RUN_OPEN), $result);
        $this->assertNothingWritten(['info', 'info'], 'previous_run_open');
        self::assertNull($this->window);
    }

    /**
     * @return iterable<string, array{OrderSyncRunStatus, string}>
     */
    public static function nonBlockingRuns(): iterable
    {
        yield 'running a day ago' => [OrderSyncRunStatus::Running, '2031-07-14T12:00:00+00:00'];
        yield 'running over a day ago' => [OrderSyncRunStatus::Running, '2031-07-13T08:00:00+00:00'];
        yield 'completed an hour ago' => [OrderSyncRunStatus::Completed, '2031-07-15T11:00:00+00:00'];
        yield 'failed an hour ago' => [OrderSyncRunStatus::Failed, '2031-07-15T11:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('nonBlockingRuns')]
    public function a_finished_or_day_old_schedule_run_does_not_block(OrderSyncRunStatus $status, string $requestedAt): void
    {
        $this->latest = self::latestRun($status, $requestedAt);

        self::assertEquals(CatchUpOrderSyncResult::queued(self::RUN_ID, 3, 1), $this->execute());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function settingsRefusals(): iterable
    {
        yield 'malformed schedule start' => [CatchUpOrderSyncResult::REFUSED_SCHEDULE_FROM_MALFORMED];
        yield 'malformed floor' => [CatchUpOrderSyncResult::REFUSED_FLOOR_MALFORMED];
        yield 'unset floor' => [CatchUpOrderSyncResult::REFUSED_FLOOR_UNSET];
    }

    #[Test]
    #[DataProvider('settingsRefusals')]
    public function a_settings_refusal_logs_at_error_and_writes_no_run(string $reason): void
    {
        match ($reason) {
            CatchUpOrderSyncResult::REFUSED_SCHEDULE_FROM_MALFORMED => $this->settingsThrow = new InvalidConfigurationException('QUICKBOOKS_ORDER_SYNC_SCHEDULE_FROM', 'Expected YYYY-MM-DD'),
            CatchUpOrderSyncResult::REFUSED_FLOOR_MALFORMED => $this->settingsThrow = new InvalidConfigurationException('QUICKBOOKS_BACKFILL_FLOOR_DATE', 'Expected YYYY-MM-DD'),
            default => $this->floor = null,
        };

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::refused($reason), $result);
        $this->assertNothingWritten(['info', 'error'], $reason);
        self::assertNull($this->window);
    }

    #[Test]
    public function no_connection_logs_at_error_and_writes_no_run(): void
    {
        $this->tokens->method('assertConnected')->willThrowException(new AuthenticationExpiredException('QuickBooks'));

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::refused(CatchUpOrderSyncResult::REFUSED_NO_CONNECTION), $result);
        $this->assertNothingWritten(['info', 'error'], 'no_connection');
    }

    #[Test]
    public function an_unresolved_mapping_logs_at_error_naming_the_key_and_writes_no_run(): void
    {
        $this->unresolved = [MappingKey::TaxZero];

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::refused(CatchUpOrderSyncResult::REFUSED_MAPPINGS_UNRESOLVED), $result);
        $this->assertNothingWritten(['info', 'error'], 'mappings_unresolved');
        self::assertSame(['tax.zero'], $this->logger->records[1][1]['missing_keys']);
    }

    #[Test]
    public function a_rejected_token_logs_at_error_and_writes_no_run(): void
    {
        $tokens = $this->createStub(QuickBooksTokenService::class);
        $tokens->method('accessToken')->willThrowException(new AuthenticationExpiredException('QuickBooks'));
        $this->tokens = $tokens;

        $result = $this->execute();

        self::assertEquals(CatchUpOrderSyncResult::refused(CatchUpOrderSyncResult::REFUSED_TOKEN_REJECTED), $result);
        $this->assertNothingWritten(['info', 'error'], 'token_rejected');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function transientTokenFailures(): iterable
    {
        yield 'lock contention' => [new LockAcquisitionException('quickbooks-token', 10)];
        yield 'Intuit unavailable' => [new ExternalServiceUnavailableException('QuickBooks')];
    }

    #[Test]
    #[DataProvider('transientTokenFailures')]
    public function any_other_failure_before_the_run_row_is_thrown_with_nothing_written(Throwable $failure): void
    {
        $tokens = $this->createStub(QuickBooksTokenService::class);
        $tokens->method('accessToken')->willThrowException($failure);
        $this->tokens = $tokens;

        try {
            $this->execute();
            self::fail('The failure was not thrown.');
        } catch (Throwable $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([], $this->runs->calls);
        self::assertSame([], $this->dispatcher->chunks);
    }

    #[Test]
    public function a_throwing_tally_release_is_rethrown(): void
    {
        $failure = new RuntimeException('test');
        $this->runs->throwOnEnd = $failure;

        try {
            $this->execute();
            self::fail('The release failure was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(['record', 'addOpenJob'], \array_column($this->runs->calls, 0));
        self::assertCount(1, $this->dispatcher->chunks);
    }

    #[Test]
    public function a_throwing_chunk_dispatch_is_rethrown_without_releasing_the_held_unit(): void
    {
        $failure = new RuntimeException('test');
        $this->dispatcher->throws = $failure;

        try {
            $this->execute();
            self::fail('The dispatch failure was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame(['record', 'addOpenJob'], \array_column($this->runs->calls, 0));
    }

    /**
     * @param list<string> $levels
     */
    private function assertNothingWritten(array $levels, string $reason): void
    {
        self::assertSame([], $this->runs->calls);
        self::assertSame([], $this->dispatcher->chunks);
        self::assertSame($levels, $this->logger->levels);
        self::assertSame(['run_id' => self::RUN_ID], $this->logger->records[0][1]);
        self::assertSame($reason, $this->logger->records[1][1]['reason']);
    }

    private function execute(): CatchUpOrderSyncResult
    {
        return $this->useCase()->execute(self::RUN_ID, new DateTimeImmutable(self::NOW));
    }

    private function useCase(): RunCatchUpOrderSyncUseCase
    {
        $settings = $this->createStub(OrderSyncSettingsInterface::class);
        $settings->method('scheduleFrom')->willReturnCallback(fn(): ?DateTimeImmutable => $this->setting('QUICKBOOKS_ORDER_SYNC_SCHEDULE_FROM', $this->scheduleFrom));
        $settings->method('backfillFloor')->willReturnCallback(fn(): ?DateTimeImmutable => $this->setting('QUICKBOOKS_BACKFILL_FLOOR_DATE', $this->floor));

        $runHistory = $this->createStub(OrderSyncRunQueryRepositoryInterface::class);
        $runHistory->method('latestFor')->willReturnCallback(fn(OrderSyncTrigger $trigger): ?OrderSyncRunDTO => $trigger === OrderSyncTrigger::Schedule ? $this->latest : null);

        $realm = $this->createStub(QuickBooksRealmInterface::class);
        $realm->method('realmId')->willReturn(self::REALM);
        $mappings = $this->createStub(QuickBooksMappingRepositoryInterface::class);
        $mappings->method('unresolvedKeys')->willReturnCallback(fn(): array => $this->unresolved);

        $sourceOrders = $this->createStub(OrderSyncSourceQueryRepositoryInterface::class);
        $sourceOrders->method('catchUpCandidates')->willReturnCallback(function (CatchUpCandidateWindowDTO $window): array {
            $this->window = $window;

            return $this->candidates;
        });

        return new RunCatchUpOrderSyncUseCase(
            $settings,
            $runHistory,
            new OrderSyncPreflightService($this->tokens, $realm, $mappings),
            $this->tokens,
            $sourceOrders,
            $this->runs,
            $this->dispatcher,
            $this->logger,
        );
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function setting(string $configKey, ?DateTimeImmutable $value): ?DateTimeImmutable
    {
        if ($this->settingsThrow !== null && $this->settingsThrow->configKey === $configKey) {
            throw $this->settingsThrow;
        }

        return $value;
    }

    private static function latestRun(OrderSyncRunStatus $status, string $requestedAt): OrderSyncRunDTO
    {
        return new OrderSyncRunDTO('9e1c7a2b-5d3f-4a6e-8b9c-1f2e3d4c5b6a', OrderSyncTrigger::Schedule, false, null, null, $status, 3, OrderSyncRunCountsDTO::zero(), new DateTimeImmutable($requestedAt), null, null);
    }
}
