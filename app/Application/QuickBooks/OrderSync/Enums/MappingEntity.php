<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum MappingEntity: string
{
    case TaxCode = 'TaxCode';
    case Account = 'Account';
    case PaymentMethod = 'PaymentMethod';
    case Department = 'Department';
    case SalesClass = 'Class';
    case Term = 'Term';
    case CustomField = 'CustomField';
}
