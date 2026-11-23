<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksRateLimitBucket;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Real Redis: proves two processes' grants stay inside every fixed window's limit.
 */
#[CoversClass(QuickBooksRateLimiter::class)]
#[Group('redis')]
final class QuickBooksRateLimiterRedisTest extends TestCase
{
    private const int WINDOW_SECONDS = 1;

    private const int LIMIT = 3;

    private const int WINDOWS = 4;

    private string $key;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'quickbooks-limiter:test-' . Str::uuid()->toString() . ':window';
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->connection()->command('del', [$this->key]);
        parent::tearDown();
    }

    #[Test]
    public function two_limiters_never_exceed_a_fixed_window(): void
    {
        $realm = Str::between($this->key, 'quickbooks-limiter:', ':window');
        $first = $this->limiter($realm);
        $second = $this->limiter($realm);

        /** @var array<string, int> $grantsPerWindow */
        $grantsPerWindow = [];
        for ($i = 0; $i < self::WINDOWS * self::LIMIT; ++$i) {
            ($i % 2 === 0 ? $first : $second)->acquire(false, Deadline::after(CarbonImmutable::now(), 10));
            $grantedAt = \microtime(true);

            $start = $this->connection()->command('hget', [$this->key, 'start']);
            $end = $this->connection()->command('hget', [$this->key, 'end']);
            $this->assertIsNumeric($start);
            $this->assertIsNumeric($end);
            $this->assertGreaterThanOrEqual((float) $start, $grantedAt, "grant {$i} came before its window opened");
            // the margin covers the round trip between the grant and reading the clock
            $this->assertLessThanOrEqual((float) $end + 0.1, $grantedAt, "grant {$i} came after its window closed");

            $grantsPerWindow[(string) $start] = ($grantsPerWindow[(string) $start] ?? 0) + 1;
        }

        foreach ($grantsPerWindow as $start => $grants) {
            $this->assertLessThanOrEqual(self::LIMIT, $grants, "grants in the window opening at {$start}");
        }

        $this->assertGreaterThanOrEqual(self::WINDOWS, \count($grantsPerWindow), 'the grants were spread over the windows');
    }

    private function connection(): Connection
    {
        return $this->app->make(RedisFactory::class)->connection(QuickBooksRateLimiter::CONNECTION);
    }

    private function limiter(string $realm): QuickBooksRateLimiter
    {
        return new QuickBooksRateLimiter(
            $this->app->make(RedisFactory::class),
            $realm,
            new NullLogger(),
            [new QuickBooksRateLimitBucket('window', self::WINDOW_SECONDS, self::LIMIT, false)],
        );
    }
}
