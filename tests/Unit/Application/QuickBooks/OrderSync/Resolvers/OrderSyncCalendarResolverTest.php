<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSyncCalendarResolver::class)]
final class OrderSyncCalendarResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function ranges(): iterable
    {
        yield 'one winter day' => ['2026-01-15', '2026-01-15', '2026-01-15T00:00:00+00:00', '2026-01-16T00:00:00+00:00'];
        yield 'one summer day' => ['2026-07-15', '2026-07-15', '2026-07-14T23:00:00+00:00', '2026-07-15T23:00:00+00:00'];
        yield 'the day the clocks go forward' => ['2026-03-29', '2026-03-29', '2026-03-29T00:00:00+00:00', '2026-03-29T23:00:00+00:00'];
        yield 'the day the clocks go back' => ['2026-10-25', '2026-10-25', '2026-10-24T23:00:00+00:00', '2026-10-26T00:00:00+00:00'];
        yield 'across the clocks going forward' => ['2026-03-28', '2026-03-30', '2026-03-28T00:00:00+00:00', '2026-03-30T23:00:00+00:00'];
        yield 'across the clocks going back' => ['2026-10-01', '2026-10-31', '2026-09-30T23:00:00+00:00', '2026-11-01T00:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('ranges')]
    public function a_range_is_bounded_by_london_days_in_utc(string $from, string $to, string $start, string $before): void
    {
        $bounds = OrderSyncCalendarResolver::rangeBounds(new DateTimeImmutable($from), new DateTimeImmutable($to));

        self::assertSame([$start, $before], \array_map(static fn(DateTimeImmutable $bound): string => $bound->format('c'), $bounds));
    }
}
