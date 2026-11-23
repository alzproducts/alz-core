<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\Enums;

/**
 * What a QuickBooks Fault means for the order being posted. Intuit codes map to these only in Infrastructure.
 */
enum QuickBooksFaultKind: string
{
    case DuplicateDocNumber = 'duplicate_doc_number';
    case DuplicateCustomerName = 'duplicate_customer_name';
    case ClosedPeriod = 'closed_period';
    case InvalidReference = 'invalid_reference';
    case Validation = 'validation';
}
