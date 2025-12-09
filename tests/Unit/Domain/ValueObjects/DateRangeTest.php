<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(DateRange::class)]
final class DateRangeTest extends TestCase
{
    #[Test]
    public function it_creates_range_with_valid_dates(): void
    {
        $from = new DateTimeImmutable('2024-01-01');
        $to = new DateTimeImmutable('2024-01-31');

        $range = new DateRange($from, $to);

        $this->assertSame($from, $range->from);
        $this->assertSame($to, $range->to);
    }

    #[Test]
    public function it_creates_range_with_same_dates(): void
    {
        $date = new DateTimeImmutable('2024-05-15');

        $range = new DateRange($date, $date);

        $this->assertSame($date, $range->from);
        $this->assertSame($date, $range->to);
    }

    #[Test]
    public function it_throws_when_from_after_to(): void
    {
        $from = new DateTimeImmutable('2024-12-31');
        $to = new DateTimeImmutable('2024-01-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('From date must be before or equal to to date');

        new DateRange($from, $to);
    }

    #[Test]
    public function single_day_factory_creates_valid_range(): void
    {
        $date = new DateTimeImmutable('2024-11-20');

        $range = DateRange::singleDay($date);

        $this->assertSame($date, $range->from);
        $this->assertSame($date, $range->to);
    }

    #[Test]
    public function it_preserves_time_component_in_dates(): void
    {
        $from = new DateTimeImmutable('2024-01-01 10:30:00');
        $to = new DateTimeImmutable('2024-01-01 18:45:00');

        $range = new DateRange($from, $to);

        $this->assertSame('10:30:00', $range->from->format('H:i:s'));
        $this->assertSame('18:45:00', $range->to->format('H:i:s'));
    }
}
