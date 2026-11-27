<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncFloorService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreflightService;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderChunkUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Fixtures\QuickBooks\SpyOrderSyncDispatcher;
use Throwable;

#[CoversClass(RunOrderChunkUseCase::class)]
#[CoversClass(OrderChunkRunDTO::class)]
#[CoversClass(OrderSyncPreflightService::class)]
final class RunOrderChunkUseCaseTest extends TestCase
{
    private const string REALM = '9130000000000001';

    private const string RUN_ID = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private SpyOrderSyncDispatcher $dispatcher;

    private QuickBooksTokenService&Stub $tokens;

    private SyncOrderChunkUseCase&Stub $syncChunk;

    private Deadline $deadline;

    private ?DateTimeImmutable $floor;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new SpyOrderSyncDispatcher();
        $this->tokens = $this->createStub(QuickBooksTokenService::class);
        $this->syncChunk = $this->createStub(SyncOrderChunkUseCase::class);
        $this->deadline = Deadline::at(new DateTimeImmutable('2026-11-24 12:00:00+00'));
        $this->floor = new DateTimeImmutable('2026-07-01');
    }

    #[Test]
    public function a_finished_chunk_is_synced_once_and_not_re_attempted(): void
    {
        $token = new ApiKeyToken('test-access-token');
        $run = self::chunkRun();
        $this->tokens = $tokens = $this->createMock(QuickBooksTokenService::class);
        $tokens->expects($this->once())->method('accessToken')->willReturn($token);
        $this->syncChunk = $syncChunk = $this->createMock(SyncOrderChunkUseCase::class);
        $syncChunk->expects($this->once())->method('execute')->with($token, $run, $this->deadline)->willReturn(self::results([]));

        $this->runChunk($run);

        self::assertSame([], $this->dispatcher->chunks);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function earlierAttempts(): iterable
    {
        yield 'first' => [1];
        yield 'second' => [2];
        yield 'fifth' => [5];
    }

    #[Test]
    #[DataProvider('earlierAttempts')]
    public function only_the_references_past_the_deadline_are_re_attempted(int $attempt): void
    {
        $this->syncReturns(self::results([2 => SyncReasonCode::DeadlineExceeded, 3 => SyncReasonCode::InProgress, 4 => SyncReasonCode::DeadlineExceeded]));

        $this->runChunk(self::chunkRun($attempt));

        self::assertEquals([self::chunkRun($attempt + 1, [2, 4])], $this->dispatcher->chunks);
    }

    #[Test]
    public function a_reference_recorded_failed_as_unavailable_is_not_re_attempted(): void
    {
        $this->syncReturns(self::results([2 => SyncReasonCode::QboUnavailable]));

        $this->runChunk(self::chunkRun());

        self::assertSame([], $this->dispatcher->chunks);
    }

    #[Test]
    public function references_in_progress_elsewhere_are_not_re_attempted(): void
    {
        $this->syncReturns(self::results([2 => SyncReasonCode::InProgress]));

        $this->runChunk(self::chunkRun(OrderChunkRunDTO::MAX_ATTEMPTS));

        self::assertSame([], $this->dispatcher->chunks);
    }

    #[Test]
    public function the_last_attempt_fails_with_exactly_the_references_past_the_deadline(): void
    {
        $this->syncReturns(self::results([2 => SyncReasonCode::DeadlineExceeded, 3 => SyncReasonCode::InProgress]));

        try {
            $this->runChunk(self::chunkRun(6));
            self::fail('The chunk did not fail.');
        } catch (OrderChunkUnfinishedException $e) {
            self::assertSame([2], $e->references);
            self::assertSame(['references' => [2]], $e->context());
        }
        self::assertSame([], $this->dispatcher->chunks);
    }

    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function transientTokenFailures(): iterable
    {
        yield 'token refresh' => ['accessToken', new ExternalServiceUnavailableException('QuickBooks')];
        yield 'token lock' => ['accessToken', new LockAcquisitionException('test', 1)];
    }

    #[Test]
    #[DataProvider('transientTokenFailures')]
    public function a_transient_token_failure_re_attempts_the_whole_chunk(string $method, Throwable $failure): void
    {
        foreach ([1, 2, 5] as $attempt) {
            $this->setUp();
            $this->tokens->method($method)->willThrowException($failure);
            $this->syncIsNeverCalled();

            $this->runChunk(self::chunkRun($attempt));

            self::assertEquals([self::chunkRun($attempt + 1)], $this->dispatcher->chunks);
        }
    }

    #[Test]
    #[DataProvider('transientTokenFailures')]
    public function the_last_attempt_fails_on_a_transient_token_failure_with_its_references(string $method, Throwable $failure): void
    {
        $this->tokens->method($method)->willThrowException($failure);

        try {
            $this->runChunk(self::chunkRun(6, [2, 4]));
            self::fail('The chunk did not fail.');
        } catch (OrderChunkUnfinishedException $e) {
            self::assertSame([2, 4], $e->references);
            self::assertSame($failure, $e->getPrevious());
        }
        self::assertSame([], $this->dispatcher->chunks);
    }

    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function otherTokenFailures(): iterable
    {
        yield 'revoked grant' => ['accessToken', new AuthenticationExpiredException('QuickBooks')];
        yield 'invalid response' => ['accessToken', new InvalidApiResponseException('QuickBooks', 'test')];
    }

    #[Test]
    #[DataProvider('otherTokenFailures')]
    public function another_token_failure_is_not_re_attempted(string $method, Throwable $failure): void
    {
        $this->tokens->method($method)->willThrowException($failure);
        $this->syncIsNeverCalled();

        $this->assertThrowsWithoutReattempt($failure, self::chunkRun());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function chunkFailures(): iterable
    {
        yield 'call-level auth' => [new AuthenticationExpiredException('QuickBooks')];
        yield 'unavailable' => [new ExternalServiceUnavailableException('QuickBooks')];
        yield 'lock' => [new LockAcquisitionException('test', 1)];
        yield 'unexpected' => [new RuntimeException('test')];
    }

    #[Test]
    #[DataProvider('chunkFailures')]
    public function a_failure_of_the_chunk_itself_is_not_re_attempted(Throwable $failure): void
    {
        $this->tokens->method('accessToken')->willReturn(new ApiKeyToken('test-access-token'));
        $this->syncChunk = $syncChunk = $this->createMock(SyncOrderChunkUseCase::class);
        $syncChunk->expects($this->once())->method('execute')->willThrowException($failure);

        $this->assertThrowsWithoutReattempt($failure, self::chunkRun());
    }

    #[Test]
    public function a_run_for_another_company_fails_before_any_token(): void
    {
        $this->tokens = $tokens = $this->createMock(QuickBooksTokenService::class);
        $tokens->expects($this->never())->method('accessToken');
        $this->syncIsNeverCalled();

        try {
            $this->runChunk(self::chunkRun(), realmId: '9130000000000002');
            self::fail('The chunk did not fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('QUICKBOOKS_REALM_ID', $e->configKey);
        }
        self::assertSame([], $this->dispatcher->chunks);
    }

    #[Test]
    public function an_unset_floor_fails_before_any_token_and_is_not_re_attempted(): void
    {
        $this->floor = null;
        $this->tokens = $tokens = $this->createMock(QuickBooksTokenService::class);
        $tokens->expects($this->never())->method('accessToken');
        $this->syncIsNeverCalled();

        try {
            $this->runChunk(self::chunkRun());
            self::fail('The chunk did not fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('QUICKBOOKS_BACKFILL_FLOOR_DATE', $e->configKey);
        }
        self::assertSame([], $this->dispatcher->chunks);
    }

    private function assertThrowsWithoutReattempt(Throwable $failure, OrderChunkRunDTO $run): void
    {
        try {
            $this->runChunk($run);
            self::fail('The chunk did not fail.');
        } catch (Throwable $e) {
            self::assertSame($failure, $e);
        }
        self::assertSame([], $this->dispatcher->chunks);
    }

    private function syncReturns(OrderSyncResultsResult $results): void
    {
        $this->tokens->method('accessToken')->willReturn(new ApiKeyToken('test-access-token'));
        $this->syncChunk->method('execute')->willReturn($results);
    }

    private function syncIsNeverCalled(): void
    {
        $this->syncChunk = $syncChunk = $this->createMock(SyncOrderChunkUseCase::class);
        $syncChunk->expects($this->never())->method('execute');
    }

    private function runChunk(OrderChunkRunDTO $run, string $realmId = self::REALM): void
    {
        $realm = $this->createStub(QuickBooksRealmInterface::class);
        $realm->method('realmId')->willReturn($realmId);

        $settings = $this->createStub(OrderSyncSettingsInterface::class);
        $settings->method('backfillFloor')->willReturn($this->floor);
        $floor = new OrderSyncFloorService($settings);
        $preflight = new OrderSyncPreflightService($this->tokens, $realm, $this->createStub(QuickBooksMappingRepositoryInterface::class));

        new RunOrderChunkUseCase($preflight, $this->tokens, $floor, $this->syncChunk, $this->dispatcher, new NullLogger())->execute($run, $this->deadline);
    }

    /**
     * @param non-empty-list<int> $references
     */
    private static function chunkRun(int $attempt = 1, array $references = [1, 2, 3, 4]): OrderChunkRunDTO
    {
        return new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $references, true, OrderSyncTrigger::Range, self::RUN_ID), $attempt);
    }

    /**
     * @param array<int, SyncReasonCode> $reasons by reference; every other one is skipped
     */
    private static function results(array $reasons): OrderSyncResultsResult
    {
        $references = [1, 2, 3, 4];

        return OrderSyncResultsResult::inRequestOrder($references, \array_map(
            static fn(int $reference): OrderSyncResultDTO => OrderSyncResultDTO::refused($reference, $reasons[$reference] ?? SyncReasonCode::OrderNotFound),
            $references,
        ));
    }
}
