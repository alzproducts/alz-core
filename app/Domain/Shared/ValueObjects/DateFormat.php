<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * British formats for human-readable dates. Not for machine formats, which stay ISO 8601.
 */
final class DateFormat
{
    /**
     * UK date format: dd/mm/yyyy
     */
    public const string DEFAULT_DATE_FORMAT = 'd/m/Y';

    public const string DEFAULT_DATE_TIME_FORMAT = 'd/m/Y H:i';

    public const string DEFAULT_DATE_TIME_SECONDS_FORMAT = 'd/m/Y H:i:s';
}
