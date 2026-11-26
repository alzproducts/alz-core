<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks;

use App\Domain\Shared\ValueObjects\Deadline;
use Carbon\CarbonImmutable;

/**
 * Every QuickBooks call a mapping route makes shares this deadline, so running out is a 503 rather than Octane
 * killing the worker at its 30 s `max_execution_time`.
 */
final class QuickBooksMappingDeadlineFactory
{
    // intentional: 6 s under Octane's limit, which also counts the time spent before the controller and on the response.
    public const int SECONDS = 24;

    public static function fromNow(): Deadline
    {
        return Deadline::after(CarbonImmutable::now(), self::SECONDS);
    }
}
