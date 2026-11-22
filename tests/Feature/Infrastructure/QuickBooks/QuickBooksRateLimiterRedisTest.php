<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksRateLimitBucket;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Real Redis: proves the script keeps two processes' grants inside every bucket's sliding window.
 */
#[CoversClass(QuickBooksRateLimiter::class)]
#[Group('redis')]
final class QuickBooksRateLimiterRedisTest extends TestCase
{
    private const int WINDOW_MS = 400;

    private const int LIMIT = 3;

    private string $realm;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->realm = 'test-' . Str::uuid()->toString();
    }

    #[Override]
    protected function tearDown(): void
    {
        $connection = $this->app->make(RedisFactory::class)->connection(QuickBooksRateLimiter::CONNECTION);
        $connection->command('del', ["quickbooks-limiter:{$this->realm}:window"]);
        parent::tearDown();
    }

    #[Test]
    public function two_limiters_never_exceed_the_window_including_across_a_window_edge(): void
    {
        $first = $this->limiter();
        $second = $this->limiter();
        $grants = [];

        for ($i = 0; $i < 4 * self::LIMIT; ++$i) {
            ($i % 2 === 0 ? $first : $second)->acquire(false, Deadline::after(CarbonImmutable::now(), 10));
            $grants[] = \hrtime(true) / 1_000_000;
        }

        $this->assertNoWindowOverLimit($grants);
        $this->assertGreaterThanOrEqual(3 * self::WINDOW_MS * 0.9, \end($grants) - $grants[0], 'the bursts were spread over the windows');
    }

    /**
     * @param list<float> $grants grant times in ms, ascending
     */
    private function assertNoWindowOverLimit(array $grants): void
    {
        // Redis TIME and the local clock differ by the round trip; a small margin keeps the check on the window, not the latency.
        $margin = 20;
        foreach ($grants as $i => $start) {
            $inWindow = \count(\array_filter($grants, static fn(float $t): bool => $t >= $start && $t < $start + self::WINDOW_MS - $margin));
            $this->assertLessThanOrEqual(self::LIMIT, $inWindow, "grants in the window opening at grant {$i}");
        }
    }

    private function limiter(): QuickBooksRateLimiter
    {
        return new QuickBooksRateLimiter(
            $this->app->make(RedisFactory::class),
            $this->realm,
            new NullLogger(),
            [new QuickBooksRateLimitBucket('window', self::WINDOW_MS, self::LIMIT, false)],
        );
    }
}
