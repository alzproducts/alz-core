<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Deadline::class)]
final class DeadlineTest extends TestCase
{
    #[Test]
    public function after_adds_the_seconds_to_now(): void
    {
        $now = new DateTimeImmutable('2026-11-21 10:00:00.250000');

        $this->assertEquals(new DateTimeImmutable('2026-11-21 10:00:30.250000'), Deadline::after($now, 30)->at);
    }

    #[Test]
    public function at_holds_the_given_instant(): void
    {
        $at = new DateTimeImmutable('2026-11-21 10:00:00');

        $this->assertSame($at, Deadline::at($at)->at);
    }

    #[Test]
    public function seconds_left_counts_fractions_and_goes_negative_once_passed(): void
    {
        $deadline = Deadline::at(new DateTimeImmutable('2026-11-21 10:00:10.000000'));

        $this->assertEqualsWithDelta(7.5, $deadline->secondsLeft(new DateTimeImmutable('2026-11-21 10:00:02.500000')), 0.000001);
        $this->assertEqualsWithDelta(-2.0, $deadline->secondsLeft(new DateTimeImmutable('2026-11-21 10:00:12.000000')), 0.000001);
    }

    #[Test]
    public function after_rejects_a_non_positive_span(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Deadline::after(new DateTimeImmutable(), 0);
    }
}
