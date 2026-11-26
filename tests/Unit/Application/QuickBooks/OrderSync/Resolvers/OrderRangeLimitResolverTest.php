<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\Resolvers\OrderRangeLimitResolver;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderRangeLimitResolver::class)]
final class OrderRangeLimitResolverTest extends TestCase
{
    private const string NOON = '2026-11-21 12:00:00+00';

    /**
     * @return iterable<string, array{string, string, string, string|null, string, string}>
     */
    public static function refusedRanges(): iterable
    {
        yield 'to after today' => ['2026-11-20', '2026-11-22', self::NOON, '2026-07-01', 'to', 'The to date must not be after today.'];
        yield 'to after today just before London midnight in summer' => ['2026-07-14', '2026-07-15', '2026-07-14 22:59:59+00', '2026-07-01', 'to', 'The to date must not be after today.'];
        yield '32 days' => ['2026-10-01', '2026-11-01', self::NOON, '2026-07-01', 'to', 'The range may span at most 31 days.'];
        yield '32 days across the clocks going forward' => ['2026-03-01', '2026-04-01', self::NOON, '2026-01-01', 'to', 'The range may span at most 31 days.'];
        yield 'from before the floor' => ['2026-06-30', '2026-07-02', self::NOON, '2026-07-01', 'from', 'The from date must not be before 2026-07-01.'];
        yield 'floor unset' => ['2026-11-01', '2026-11-02', self::NOON, null, 'from', 'The QuickBooks backfill floor date is not set.'];
        yield 'floor unset before any other limit' => ['2026-10-01', '2026-11-30', self::NOON, null, 'from', 'The QuickBooks backfill floor date is not set.'];
    }

    #[Test]
    #[DataProvider('refusedRanges')]
    public function a_range_past_a_limit_is_refused_under_its_field(string $from, string $to, string $now, ?string $floor, string $field, string $reason): void
    {
        try {
            self::assertWithinLimits($from, $to, $now, $floor);
        } catch (ValidationFailedException $e) {
            self::assertSame('The date range is outside the allowed limits.', $e->reason);
            self::assertSame([$field => [$reason]], $e->context);

            return;
        }

        self::fail('The range was accepted.');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedRanges(): iterable
    {
        yield 'one day' => ['2026-11-20', '2026-11-20', self::NOON];
        yield 'to today' => ['2026-11-20', '2026-11-21', self::NOON];
        yield '31 days' => ['2026-10-01', '2026-10-31', self::NOON];
        yield 'from on the floor' => ['2026-07-01', '2026-07-02', self::NOON];
        yield 'to today between 00:00 and 01:00 BST, still yesterday in UTC' => ['2026-07-14', '2026-07-15', '2026-07-14 23:30:00+00'];
    }

    #[Test]
    #[DataProvider('acceptedRanges')]
    public function a_range_at_a_limit_is_accepted(string $from, string $to, string $now): void
    {
        $this->expectNotToPerformAssertions();

        self::assertWithinLimits($from, $to, $now, '2026-07-01');
    }

    private static function assertWithinLimits(string $from, string $to, string $now, ?string $floor): void
    {
        OrderRangeLimitResolver::assertWithinLimits(
            new DateRange(new DateTimeImmutable($from), new DateTimeImmutable($to)),
            new DateTimeImmutable($now),
            $floor === null ? null : new DateTimeImmutable($floor),
        );
    }
}
