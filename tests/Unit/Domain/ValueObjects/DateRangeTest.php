<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\DateRange;
use DateInterval;
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

    /*
    |--------------------------------------------------------------------------
    | chunk() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function chunk_returns_single_element_when_range_smaller_than_chunk_size(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-05'),
        );

        $chunks = $range->chunk(7);

        $this->assertCount(1, $chunks);
        $this->assertEquals($range->from, $chunks[0]->from);
        $this->assertEquals($range->to, $chunks[0]->to);
    }

    #[Test]
    public function chunk_returns_single_element_for_single_day_range(): void
    {
        $range = DateRange::singleDay(new DateTimeImmutable('2024-03-15'));

        $chunks = $range->chunk(7);

        $this->assertCount(1, $chunks);
        $this->assertEquals($range->from, $chunks[0]->from);
        $this->assertEquals($range->to, $chunks[0]->to);
    }

    #[Test]
    public function chunk_splits_range_into_non_overlapping_boundaries(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-15'),
        );

        $chunks = $range->chunk(7);

        $this->assertCount(3, $chunks);

        // First chunk: Jan 1 → Jan 7 (7 inclusive days)
        $this->assertSame('2024-01-01', $chunks[0]->from->format('Y-m-d'));
        $this->assertSame('2024-01-07', $chunks[0]->to->format('Y-m-d'));

        // Second chunk: Jan 8 → Jan 14 (7 inclusive days)
        $this->assertSame('2024-01-08', $chunks[1]->from->format('Y-m-d'));
        $this->assertSame('2024-01-14', $chunks[1]->to->format('Y-m-d'));

        // Third chunk: Jan 15 → Jan 15 (1 remaining day)
        $this->assertSame('2024-01-15', $chunks[2]->from->format('Y-m-d'));
        $this->assertSame('2024-01-15', $chunks[2]->to->format('Y-m-d'));
    }

    #[Test]
    public function chunk_final_chunk_is_shorter_when_range_not_evenly_divisible(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-10'),
        );

        $chunks = $range->chunk(7);

        $this->assertCount(2, $chunks);

        // First chunk: 7 inclusive days (Jan 1-7)
        $this->assertSame('2024-01-01', $chunks[0]->from->format('Y-m-d'));
        $this->assertSame('2024-01-07', $chunks[0]->to->format('Y-m-d'));

        // Second chunk: 3 remaining days (Jan 8-10)
        $this->assertSame('2024-01-08', $chunks[1]->from->format('Y-m-d'));
        $this->assertSame('2024-01-10', $chunks[1]->to->format('Y-m-d'));
    }

    #[Test]
    public function chunk_large_range_produces_correct_count(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-03-31'),
        );

        $chunks = $range->chunk(7);

        // 91 days (Jan 1 to Mar 31 inclusive) / 7 = 13 chunks
        $this->assertCount(13, $chunks);

        // First chunk starts at range start
        $this->assertSame('2024-01-01', $chunks[0]->from->format('Y-m-d'));

        // Last chunk ends at range end
        $this->assertSame('2024-03-31', $chunks[12]->to->format('Y-m-d'));
    }

    #[Test]
    public function chunk_preserves_time_component(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01 14:30:00'),
            new DateTimeImmutable('2024-01-20 14:30:00'),
        );

        $chunks = $range->chunk(7);

        $this->assertCount(3, $chunks);

        // Time component preserved on all boundaries
        $this->assertSame('14:30:00', $chunks[0]->from->format('H:i:s'));
        $this->assertSame('14:30:00', $chunks[0]->to->format('H:i:s'));
        $this->assertSame('14:30:00', $chunks[1]->from->format('H:i:s'));
        $this->assertSame('14:30:00', $chunks[2]->to->format('H:i:s'));
    }

    #[Test]
    public function chunk_returns_single_element_when_range_equals_chunk_size(): void
    {
        // 7 inclusive days: Jan 1 through Jan 7
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-07'),
        );

        $chunks = $range->chunk(7);

        $this->assertCount(1, $chunks);
        $this->assertSame('2024-01-01', $chunks[0]->from->format('Y-m-d'));
        $this->assertSame('2024-01-07', $chunks[0]->to->format('Y-m-d'));
    }

    #[Test]
    public function chunk_boundaries_are_contiguous_without_overlap(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-02-01'),
        );

        $chunks = $range->chunk(7);

        // Each chunk's to + 1 day === next chunk's from (no gaps, no overlaps)
        $oneDay = new DateInterval('P1D');

        for ($i = 0; $i < \count($chunks) - 1; $i++) {
            $this->assertEquals(
                $chunks[$i]->to->add($oneDay),
                $chunks[$i + 1]->from,
                "Gap or overlap between chunk {$i} and " . ($i + 1),
            );
        }

        // First and last match the original range
        $this->assertEquals($range->from, $chunks[0]->from);
        $this->assertEquals($range->to, $chunks[\count($chunks) - 1]->to);
    }
}
