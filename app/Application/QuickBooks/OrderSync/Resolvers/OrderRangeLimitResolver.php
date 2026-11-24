<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Domain\Exceptions\ValidationFailedException;
use DateTimeImmutable;

/**
 * The contract's limits on a range of business days, refused under the field each one belongs to.
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
    public static function assertWithinLimits(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, ?DateTimeImmutable $floor): void
    {
        $refusal = $floor === null
            ? self::refused('from', 'The QuickBooks backfill floor date is not set.')
            : self::refusal($from, $to, OrderSyncCalendarResolver::documentDate($now), $floor);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    private static function refusal(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, DateTimeImmutable $floor): ?ValidationFailedException
    {
        $fromDate = $from->format(self::DATE);
        $toDate = $to->format(self::DATE);
        $floorDate = $floor->format(self::DATE);

        return match (true) {
            $toDate < $fromDate => self::refused('to', 'The to date must not be before the from date.'),
            $toDate > $today->format(self::DATE) => self::refused('to', 'The to date must not be after today.'),
            $toDate > self::lastDateAllowedFrom($from) => self::refused('to', 'The range may span at most 31 days.'),
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
