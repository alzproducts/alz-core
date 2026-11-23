<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum SyncOutcome: string
{
    case Synced = 'synced';
    case AlreadySynced = 'already_synced';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case NotAttempted = 'not_attempted';
}
