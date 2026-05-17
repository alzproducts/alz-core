<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

enum ContactSubmissionFilterField: string
{
    case HasGclid = 'has_gclid';
    case IsPotentialQuote = 'is_potential_quote';
    case DateFrom = 'date_from';
    case DateTo = 'date_to';
    case ConversionStatus = 'conversion_status';
}
