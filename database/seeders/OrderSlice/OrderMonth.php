<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * The one complete calendar month every seeded order is placed in: October 2026, already ended when the slice was
 * written. Absolute timestamps, never relative to the time of seeding, so a range run covers the whole set.
 */
final class OrderMonth
{
    /** 2026-10-01 12:00:00 UTC; midday keeps every timestamp inside October as a Europe/London date too. */
    public const int FIRST = 1_790_856_000;

    /** 2026-10-31 12:00:00 UTC */
    public const int LAST = 1_793_448_000;
}
