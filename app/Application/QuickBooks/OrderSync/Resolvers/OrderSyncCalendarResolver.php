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
}
