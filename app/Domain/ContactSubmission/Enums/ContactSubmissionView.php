<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

enum ContactSubmissionView: string
{
    case Triage = 'triage';
    case AwaitingQuote = 'awaiting-quote';
    case Failed = 'failed';
    case Completed = 'completed';
}
