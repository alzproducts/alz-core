<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;

/**
 * The limits on a range of business days, refused under the field each one belongs to.
 */
final class OrderRangeLimitResolver
{
    private const int MAX_DAYS = 31;

    private const string DATE = 'Y-m-d';

    /**
     * `$now` is an instant; the others are calendar dates, whose own times and timezones are ignored.
     *
     * @throws ValidationFailedException
     */
    public static function assertWithinLimits(DateRange $range, DateTimeImmutable $now, ?DateTimeImmutable $floor): void
    {
        $refusal = $floor === null
            ? self::refused('from', 'The QuickBooks backfill floor date is not set.')
            : self::refusal($range, OrderSyncCalendarResolver::documentDate($now), $floor);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    private static function refusal(DateRange $range, DateTimeImmutable $today, DateTimeImmutable $floor): ?ValidationFailedException
    {
        $fromDate = $range->from->format(self::DATE);
        $toDate = $range->to->format(self::DATE);
        $floorDate = $floor->format(self::DATE);

        return match (true) {
            $toDate > $today->format(self::DATE) => self::refused('to', 'The to date must not be after today.'),
            $toDate > self::lastDateAllowedFrom($range->from) => self::refused('to', 'The range may span at most 31 days.'),
            $fromDate < $floorDate => self::refused('from', "The from date must not be before {$floorDate}."),
            default => null,
        };
    }

    private static function lastDateAllowedFrom(DateTimeImmutable $from): string
    {
        return $from->modify('+' . (self::MAX_DAYS - 1) . ' days')->format(self::DATE);
    }

    private static function refused(string $field, string $reason): ValidationFailedException
    {
        return new ValidationFailedException('The date range is outside the allowed limits.', [$field => [$reason]]);
    }
}
