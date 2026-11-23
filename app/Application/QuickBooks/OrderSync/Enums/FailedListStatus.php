<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum FailedListStatus: string
{
    case Failed = 'failed';
    case ChangedAfterSync = 'changed_after_sync';
}
