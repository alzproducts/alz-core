<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use ErrorException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RedisException;
use RuntimeException;

/**
 * The wrapper around Laravel's Redis throttle: which buckets a call counts against, the wait bound, and failing closed.
 * Each Redis call answers a grant script: [granted, windowEnd, remaining].
 */
#[CoversClass(QuickBooksRateLimiter::class)]
final class QuickBooksRateLimiterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string NOW = '2026-11-22 10:00:00';

    private const array GRANTED = [1, 0, 4];

    private const array DENIED = [0, 0, 0];

    /** @var list<float> */
    private array $slept = [];

    /** @var list<string> the bucket key of each Redis call */
    private array $keys = [];

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
    public function a_plain_call_takes_a_slot_in_the_realm_buckets_only(): void
    {
        $this->limiter([self::GRANTED, self::GRANTED])->acquireBy(false, self::startBy(10));

        $this->assertSame(['quickbooks-limiter:realm:second', 'quickbooks-limiter:realm:minute'], $this->keys);
        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function a_batch_call_also_counts_against_the_batch_bucket(): void
    {
        $this->limiter([self::GRANTED, self::GRANTED, self::GRANTED])->acquire(true);

        $this->assertSame('quickbooks-limiter:realm:batch-minute', $this->keys[2]);
    }

    #[Test]
    public function a_full_bucket_is_polled_until_it_has_room(): void
    {
        $this->limiter([self::GRANTED, self::DENIED, self::GRANTED])->acquireBy(false, self::startBy(10));

        $this->assertSame([250.0], $this->slept);
    }

    #[Test]
    public function no_room_before_the_start_by_time_raises_the_deadline_error(): void
    {
        $this->expectException(QuickBooksDeadlineExceededException::class);

        try {
            $this->limiter(null)->acquireBy(false, self::startBy(2));
        } finally {
            $this->assertSame(2_000.0, \array_sum($this->slept));
        }
    }

    #[Test]
    public function no_room_without_a_deadline_is_unavailable_after_the_fixed_wait(): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        try {
            $this->limiter(null)->acquire(false);
        } finally {
            $this->assertSame(QuickBooksRateLimiter::NO_DEADLINE_WAIT_SECONDS * 1_000.0, \array_sum($this->slept));
        }
    }

    #[Test]
    public function a_redis_error_fails_closed(): void
    {
        $connection = self::connection();
        $connection->shouldReceive('command')->once()->andThrow(new RedisException('connection refused'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->limiterOn($connection)->acquire(false);
    }

    #[Test]
    public function a_false_script_reply_sends_nothing(): void
    {
        $connection = self::connection();
        $connection->shouldReceive('command')->once()->andReturn(false);

        // intentional: Laravel's error handler turns the PHP warning on reading false into an ErrorException
        \set_error_handler(static fn(int $level, string $message): never => throw new ErrorException($message, 0, $level));

        try {
            $this->expectException(ExternalServiceUnavailableException::class);
            $this->limiterOn($connection)->acquire(false);
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function an_unreachable_connection_fails_closed(): void
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->once()->andThrow(new RuntimeException('no such connection'));

        $this->expectException(ExternalServiceUnavailableException::class);

        new QuickBooksRateLimiter($redis, 'realm', new NullLogger(), QuickBooksRateLimiter::defaultBuckets())->acquire(false);
    }

    private static function startBy(int $seconds): Deadline
    {
        return Deadline::after(CarbonImmutable::now(), $seconds);
    }

    private static function connection(): PhpRedisConnection&Mockery\MockInterface
    {
        // intentional: partial, so the real throttle() and eval() run and only command() is scripted
        return Mockery::mock(PhpRedisConnection::class)->makePartial();
    }

    /**
     * @param list<list<int>>|null $replies one per Redis call, in order; null answers every call with no room
     */
    private function limiter(?array $replies): QuickBooksRateLimiter
    {
        $connection = self::connection();
        $connection->shouldReceive('command')
            ->with('eval', Mockery::type('array'))
            ->andReturnUsing(function (string $method, array $parameters) use (&$replies): mixed {
                $this->keys[] = $parameters[1][0];

                return $replies === null ? self::DENIED : \array_shift($replies);
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
