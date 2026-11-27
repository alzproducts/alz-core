<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks;

use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * Every QuickBooks call a mapping route makes shares this deadline, so running out is a 503 rather than Octane
 * killing the worker at its 30 s `max_execution_time`.
 */
final class QuickBooksMappingDeadlineFactory
{
    // intentional: counted from the request's arrival, so authentication (a JWKS fetch on a cache miss) uses it up too;
    // the 6 s left under Octane's limit covers the response.
    public const int SECONDS = 24;

    public static function forRequest(Request $request): Deadline
    {
        $arrivedAt = $request->server('REQUEST_TIME_FLOAT');
        $start = \is_numeric($arrivedAt) ? DateTimeImmutable::createFromTimestamp((float) $arrivedAt) : new DateTimeImmutable();

        return Deadline::after($start, self::SECONDS);
    }
}
