<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

/**
 * Conversion status of a contact submission, derived from its `customer_service.contact_submission_actions`.
 *
 * Each case is an independent EXISTS predicate against the actions table — a submission may match
 * multiple states simultaneously (e.g. lead sent AND quote pending). The dashboard filter is
 * single-select for UI clarity, not because the cases are mutually exclusive.
 */
enum ConversionStatus: string
{
    case None = 'none';
    case LeadPending = 'lead_pending';
    case LeadSent = 'lead_sent';
    case QuotePending = 'quote_pending';
    case QuoteSent = 'quote_sent';
}
