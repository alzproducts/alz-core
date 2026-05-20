<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

/**
 * Workflow view identifier for the staff contact-submissions dashboard.
 *
 * Each case names a saved query (filter set + sort order) over
 * `marketing.contact_submission_dashboard_view`. The cases form the 4-stage staff
 * workflow (Triage → Awaiting Quote → Failed → Completed) — the same submission
 * moves between views as actions are recorded.
 */
enum ContactSubmissionView: string
{
    case Triage = 'triage';
    case AwaitingQuote = 'awaiting-quote';
    case Failed = 'failed';
    case Completed = 'completed';
}
