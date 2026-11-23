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

        // intentional: a constant spec, so the interval cannot throw on a malformed string
        $interval = new DateInterval('PT0S');
        $interval->s = $seconds;

        return new self($now->add($interval));
    }

    /**
     * Negative once the deadline has passed.
     */
    public function secondsLeft(DateTimeImmutable $now): float
    {
        return (float) $this->at->format('U.u') - (float) $now->format('U.u');
    }
}
