<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

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
}
