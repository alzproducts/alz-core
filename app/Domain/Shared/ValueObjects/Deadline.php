<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use DateInterval;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * A point in time an operation must not run past. It never reads the clock: callers pass `now`.
 */
final readonly class Deadline
{
    private function __construct(
        public DateTimeImmutable $at,
    ) {}

    public static function at(DateTimeImmutable $at): self
    {
        return new self($at);
    }

    public static function after(DateTimeImmutable $now, int $seconds): self
    {
        Assert::greaterThan($seconds, 0, 'A deadline must lie after now');

        return new self($now->add(self::interval($seconds)));
    }

    public static function before(DateTimeImmutable $at, int $seconds): self
    {
        Assert::greaterThan($seconds, 0, 'A deadline must lie before the instant');

        // intentional: adds a negative interval, because DateTimeImmutable::sub() declares a checked exception
        return new self($at->add(self::interval(-$seconds)));
    }

    public function earlier(self $other): self
    {
        return $other->at < $this->at ? $other : $this;
    }

    /**
     * Negative once the deadline has passed.
     */
    public function secondsLeft(DateTimeImmutable $now): float
    {
        return (float) $this->at->format('U.u') - (float) $now->format('U.u');
    }

    private static function interval(int $seconds): DateInterval
    {
        // intentional: a constant spec, so the interval cannot throw on a malformed string
        $interval = new DateInterval('PT0S');
        $interval->s = $seconds;

        return $interval;
    }
}
