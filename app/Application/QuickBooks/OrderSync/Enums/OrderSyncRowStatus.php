<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum OrderSyncRowStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Synced = 'synced';
    case Failed = 'failed';
}
