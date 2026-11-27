<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum OrderSyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
