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
use Illuminate\Redis\Connections\Connection;
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

#[CoversClass(QuickBooksRateLimiter::class)]
final class QuickBooksRateLimiterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<float> */
    private array $slept = [];

    /** @var list<array<mixed>> */
    private array $calls = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 10:00:00');
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
    public function an_immediate_grant_does_not_sleep(): void
    {
        $this->limiter([0])->acquire(false, self::startBy(10));

        $this->assertSame([], $this->slept);
        $this->assertCount(1, $this->calls);
    }

    #[Test]
    public function a_full_bucket_is_waited_out_then_granted(): void
    {
        $this->limiter([750, 0])->acquire(false, self::startBy(10));

        $this->assertSame([750.0], $this->slept);
        $this->assertCount(2, $this->calls);
    }

    #[Test]
    public function a_wait_past_the_start_by_time_raises_the_deadline_error_without_a_grant(): void
    {
        try {
            $this->limiter([2_500])->acquire(false, self::startBy(2));
            $this->fail('Expected QuickBooksDeadlineExceededException');
        } catch (QuickBooksDeadlineExceededException $e) {
            $this->assertEqualsWithDelta(2.0, $e->secondsLeft, 0.001);
        }

        $this->assertSame([], $this->slept);
        $this->assertCount(1, $this->calls);
    }

    #[Test]
    public function a_later_wait_is_checked_against_the_time_left_after_earlier_waits(): void
    {
        $this->expectException(QuickBooksDeadlineExceededException::class);

        try {
            $this->limiter([1_500, 1_000])->acquire(false, self::startBy(2));
        } finally {
            $this->assertSame([1_500.0], $this->slept);
        }
    }

    #[Test]
    public function a_redis_error_fails_closed(): void
    {
        $connection = Mockery::mock(Connection::class);
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

    #[Test]
    #[DataProvider('oddReplies')]
    public function a_reply_that_is_not_a_wait_time_fails_closed(mixed $reply): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        $this->limiter([$reply])->acquire(false, self::startBy(10));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function oddReplies(): array
    {
        return [
            'false (script error)' => [false],
            'null' => [null],
            'string' => ['0'],
            'negative' => [-1],
        ];
    }

    #[Test]
    public function a_plain_call_counts_against_the_realm_buckets_only(): void
    {
        $this->limiter([0])->acquire(false, self::startBy(10));

        [, $arguments, $keyCount] = $this->calls[0];
        $this->assertSame(2, $keyCount);
        $this->assertSame(['quickbooks-limiter:realm:second', 'quickbooks-limiter:realm:minute'], \array_slice($arguments, 0, 2));
        $this->assertSame([1_000, 10, 60_000, 500], \array_slice($arguments, 2, 4));
    }

    #[Test]
    public function a_batch_call_also_counts_against_the_batch_bucket(): void
    {
        $this->limiter([0])->acquire(true, self::startBy(10));

        [, $arguments, $keyCount] = $this->calls[0];
        $this->assertSame(3, $keyCount);
        $this->assertSame('quickbooks-limiter:realm:batch-minute', $arguments[2]);
        $this->assertSame([1_000, 10, 60_000, 500, 60_000, 40], \array_slice($arguments, 3, 6));
    }

    #[Test]
    public function each_attempt_carries_a_fresh_grant_id(): void
    {
        $this->limiter([500, 0])->acquire(false, self::startBy(10));

        $this->assertNotSame(\end($this->calls[0][1]), \end($this->calls[1][1]));
    }

    private static function startBy(int $seconds): Deadline
    {
        return Deadline::after(CarbonImmutable::now(), $seconds);
    }

    /**
     * @param list<mixed> $replies one per script call, in order
     */
    private function limiter(array $replies): QuickBooksRateLimiter
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')
            ->times(\count($replies))
            ->with('eval', Mockery::type('array'))
            ->andReturnUsing(function (string $method, array $parameters) use (&$replies): mixed {
                $this->calls[] = $parameters;

                return \array_shift($replies);
            });

        return $this->limiterOn($connection);
    }

    private function limiterOn(Connection $connection): QuickBooksRateLimiter
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->with(QuickBooksRateLimiter::CONNECTION)->andReturn($connection);

        return new QuickBooksRateLimiter($redis, 'realm', new NullLogger(), QuickBooksRateLimiter::defaultBuckets());
    }
}
