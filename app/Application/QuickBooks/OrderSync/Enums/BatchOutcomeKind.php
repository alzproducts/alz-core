<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum BatchOutcomeKind: string
{
    case Created = 'created';

    /** QBO rejected the document; nothing posted. */
    case Faulted = 'faulted';

    /** The batch hit QBO's rate limit and no retry could start in time; nothing posted. */
    case RateLimited = 'rate_limited';

    /** The document may or may not have posted. */
    case Ambiguous = 'ambiguous';
}
