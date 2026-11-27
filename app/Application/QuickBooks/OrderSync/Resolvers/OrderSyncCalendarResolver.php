<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The order sync's business calendar: which day an order belongs to.
 */
final class OrderSyncCalendarResolver
{
    private const string BUSINESS_TIMEZONE = 'Europe/London';

    /**
     * The same instant in the business timezone, so its date part is the document's date.
     */
    public static function documentDate(DateTimeImmutable $placedAt): DateTimeImmutable
    {
        return $placedAt->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }

    /**
     * The instant the calendar date starts in the business timezone; the argument's own time and timezone are ignored.
     */
    public static function startOfDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE))
            ->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'))
            ->setTime(0, 0);
    }

    /**
     * The half-open bounds of an inclusive range of business days, as instants in the business timezone.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable} the start of `from`, and the start of the day after `to`
     */
    public static function rangeBounds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [self::startOfDate($from), self::startOfDate($to->modify('+1 day'))];
    }
}
