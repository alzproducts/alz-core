<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Results;

enum QuickBooksCallbackResult: string
{
    case Connected = 'connected';
    case InvalidState = 'invalid_state';
    case MissingCode = 'missing_code';
    case RealmMismatch = 'realm_mismatch';
}
