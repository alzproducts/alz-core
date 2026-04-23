<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use DateInterval;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Represents a range between two dates (inclusive).
 *
 * Invariant: from <= to.
 */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        Assert::true($from <= $to, 'From date must be before or equal to to date');
    }

    /**
     * Create a range spanning a single day.
     */
    public static function singleDay(DateTimeImmutable $date): self
    {
        return new self($date, $date);
    }

    /**
     * Split this range into sub-ranges of N days.
     *
     * Each chunk starts where the previous ended, with the final chunk
     * capped at $this->to. Returns a single-element array when the
     * range is smaller than or equal to the chunk size.
     *
     * @param positive-int $days
     *
     * @return list<self>
     */
    public function chunk(int $days): array
    {
        Assert::greaterThan($days, 0, 'Chunk size must be positive');

        $step = new DateInterval("P{$days}D");
        $endOffset = new DateInterval('P' . ($days - 1) . 'D');
        $chunks = [];
        $cursor = $this->from;

        while ($cursor <= $this->to) {
            $chunks[] = new self($cursor, $this->clampToRangeEnd($cursor->add($endOffset)));
            $cursor = $cursor->add($step);
        }

        return $chunks;
    }

    private function clampToRangeEnd(DateTimeImmutable $candidate): DateTimeImmutable
    {
        return $candidate > $this->to ? $this->to : $candidate;
    }
}
