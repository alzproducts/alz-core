<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RedisException;
use RuntimeException;

/**
 * The wrapper around Laravel's duration limiter: which buckets a call counts against, the deadline, and failing closed.
 * Replies are scripted per Redis call in order: a check answers [windowEnd, remaining], a grant [granted, windowEnd, remaining].
 */
#[CoversClass(QuickBooksRateLimiter::class)]
final class QuickBooksRateLimiterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string NOW = '2026-11-22 10:00:00';

    /** @var list<float> */
    private array $slept = [];

    /** @var list<array{string, string}> kind ('check' or 'grant') and key, per Redis call */
    private array $calls = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            $this->slept[] = $duration->totalMilliseconds;
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_plain_call_checks_then_takes_a_slot_in_the_realm_buckets_only(): void
    {
        $this->limiter([self::room(), self::room(), self::granted(), self::granted()])->acquire(false, self::startBy(10));

        $this->assertSame([
            ['check', 'quickbooks-limiter:realm:second'],
            ['check', 'quickbooks-limiter:realm:minute'],
            ['grant', 'quickbooks-limiter:realm:second'],
            ['grant', 'quickbooks-limiter:realm:minute'],
        ], $this->calls);
        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function a_batch_call_also_counts_against_the_batch_bucket(): void
    {
        $this->limiter([self::room(), self::room(), self::room(), self::granted(), self::granted(), self::granted()])->acquire(true, self::startBy(10));

        $this->assertSame(['check', 'quickbooks-limiter:realm:batch-minute'], $this->calls[2]);
        $this->assertSame(['grant', 'quickbooks-limiter:realm:batch-minute'], $this->calls[5]);
    }

    #[Test]
    public function a_full_bucket_is_waited_out_before_any_slot_is_taken(): void
    {
        $this->limiter([self::full(1), self::room(), self::room(), self::room(), self::granted(), self::granted()])->acquire(false, self::startBy(10));

        $this->assertSame([1_000.0], $this->slept);
        $this->assertSame(['check', 'check', 'check', 'check', 'grant', 'grant'], \array_column($this->calls, 0));
    }

    #[Test]
    public function a_wait_past_the_start_by_time_raises_the_deadline_error_without_taking_a_slot(): void
    {
        try {
            $this->limiter([self::room(), self::full(3)])->acquire(false, self::startBy(2));
            $this->fail('Expected QuickBooksDeadlineExceededException');
        } catch (QuickBooksDeadlineExceededException $e) {
            $this->assertEqualsWithDelta(2.0, $e->secondsLeft, 0.001);
        }

        $this->assertSame([], $this->slept);
        $this->assertSame(['check', 'check'], \array_column($this->calls, 0));
    }

    #[Test]
    public function a_call_without_a_deadline_waits_at_most_the_fixed_timeout(): void
    {
        $this->expectException(QuickBooksDeadlineExceededException::class);

        try {
            $this->limiter([self::room(), self::full(QuickBooksRateLimiter::NO_DEADLINE_WAIT_SECONDS + 1)])->acquire(false);
        } finally {
            $this->assertSame([], $this->slept);
        }
    }

    #[Test]
    public function a_call_without_a_deadline_waits_out_a_full_minute_window(): void
    {
        $this->limiter([self::room(), self::full(QuickBooksRateLimiter::NO_DEADLINE_WAIT_SECONDS), self::room(), self::room(), self::granted(), self::granted()])->acquire(false);

        $this->assertSame([60_000.0], $this->slept);
    }

    #[Test]
    public function a_slot_lost_to_another_process_is_retried_after_the_window(): void
    {
        $this->limiter([
            self::room(), self::room(), self::denied(1),
            self::full(1), self::room(),
            self::room(), self::room(), self::granted(), self::granted(),
        ])->acquire(false, self::startBy(10));

        $this->assertSame([1_000.0], $this->slept);
    }

    #[Test]
    public function a_redis_error_fails_closed(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('command')->once()->andThrow(new RedisException('connection refused'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->limiterOn($connection)->acquire(false, self::startBy(10));
    }

    #[Test]
    public function an_unreachable_connection_fails_closed(): void
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->once()->andThrow(new RuntimeException('no such connection'));

        $this->expectException(ExternalServiceUnavailableException::class);

        new QuickBooksRateLimiter($redis, 'realm', new NullLogger(), QuickBooksRateLimiter::defaultBuckets())->acquire(false, self::startBy(10));
    }

    /**
     * @param list<mixed> $replies
     */
    #[Test]
    #[DataProvider('oddReplies')]
    public function an_odd_reply_fails_closed_without_a_grant(array $replies): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        $this->limiter($replies)->acquire(false, self::startBy(10));
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function oddReplies(): array
    {
        return [
            'false on the check (phpredis script error)' => [[false]],
            'null on the check' => [[null]],
            'no window end on the check' => [[[null, 10]]],
            'a non-numeric window end on the check' => [[['soon', 10]]],
            'no window end on the grant' => [[self::room(), self::room(), [1, null, 9]]],
        ];
    }

    private static function startBy(int $seconds): Deadline
    {
        return Deadline::after(CarbonImmutable::now(), $seconds);
    }

    /**
     * @return array{int, int}
     */
    private static function room(): array
    {
        return [self::at(1), 5];
    }

    /**
     * @return array{int, int}
     */
    private static function full(int $windowEndsIn): array
    {
        return [self::at($windowEndsIn), 0];
    }

    /**
     * @return array{int, int, int}
     */
    private static function granted(): array
    {
        return [1, self::at(1), 4];
    }

    /**
     * @return array{int, int, int}
     */
    private static function denied(int $windowEndsIn): array
    {
        return [0, self::at($windowEndsIn), 0];
    }

    private static function at(int $seconds): int
    {
        return CarbonImmutable::parse(self::NOW)->addSeconds($seconds)->getTimestamp();
    }

    /**
     * @param list<mixed> $replies one per Redis call, in order
     */
    private function limiter(array $replies): QuickBooksRateLimiter
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        // intentional: Mockery cannot override a method named eval, so the real one runs and forwards to command()
        $connection->shouldReceive('command')
            ->times(\count($replies))
            ->with('eval', Mockery::type('array'))
            ->andReturnUsing(function (string $method, array $parameters) use (&$replies): mixed {
                [$script, $arguments] = $parameters;
                $this->calls[] = [\str_contains($script, 'HINCRBY') ? 'grant' : 'check', $arguments[0]];

                return \array_shift($replies);
            });

        return $this->limiterOn($connection);
    }

    private function limiterOn(PhpRedisConnection $connection): QuickBooksRateLimiter
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->with(QuickBooksRateLimiter::CONNECTION)->andReturn($connection);

        return new QuickBooksRateLimiter($redis, 'realm', new NullLogger(), QuickBooksRateLimiter::defaultBuckets());
    }
}
