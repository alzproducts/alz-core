<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\ActionAttempts;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionAttempts::class)]
final class ActionAttemptsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function backoffSchedule(): iterable
    {
        yield 'first attempt' => [1, 60];
        yield 'second attempt' => [2, 300];
        yield 'third attempt' => [3, 3600];
        yield 'fourth attempt' => [4, 43200];
        yield 'fifth attempt reuses the last delay' => [5, 43200];
        yield 'past the limit reuses the last delay' => [6, 43200];
    }

    #[Test]
    #[DataProvider('backoffSchedule')]
    public function retry_delay_follows_the_backoff_schedule_without_a_retry_after(int $count, int $expectedDelay): void
    {
        self::assertSame($expectedDelay, new ActionAttempts($count)->retryDelay(null));
    }

    #[Test]
    public function retry_delay_prefers_the_retry_after_hint(): void
    {
        self::assertSame(17, new ActionAttempts(4)->retryDelay(17));
    }

    #[Test]
    public function retry_delay_honours_a_zero_retry_after(): void
    {
        self::assertSame(0, new ActionAttempts(1)->retryDelay(0));
    }

    #[Test]
    public function is_last_only_at_the_limit(): void
    {
        self::assertFalse(new ActionAttempts(ActionAttempts::LIMIT - 1)->isLast());
        self::assertTrue(new ActionAttempts(ActionAttempts::LIMIT)->isLast());
        self::assertFalse(new ActionAttempts(ActionAttempts::LIMIT + 1)->isLast());
    }

    #[Test]
    public function is_past_limit_only_above_the_limit(): void
    {
        self::assertFalse(new ActionAttempts(ActionAttempts::LIMIT)->isPastLimit());
        self::assertTrue(new ActionAttempts(ActionAttempts::LIMIT + 1)->isPastLimit());
    }

    #[Test]
    public function limit_is_five(): void
    {
        self::assertSame(5, ActionAttempts::LIMIT);
    }

    #[Test]
    public function rejects_a_count_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ActionAttempts(0);
    }
}
