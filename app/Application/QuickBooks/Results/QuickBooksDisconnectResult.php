<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Results;

enum QuickBooksDisconnectResult: string
{
    case NotConnected = 'not_connected';
    case DryRun = 'dry_run';
    case Revoked = 'revoked';
    case RemovedWithoutRevoke = 'removed_without_revoke';
}
