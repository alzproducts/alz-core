<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

/**
 * Semantic key for a QBO ref that is the same on every document (ADR-0018).
 */
enum FixedRefKey: string
{
    case Department = 'department';
    case SalesClass = 'class';
    case DefaultTerms = 'default_terms';
}
