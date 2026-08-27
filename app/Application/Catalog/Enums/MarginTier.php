<?php

declare(strict_types=1);

namespace App\Application\Catalog\Enums;

enum MarginTier: string
{
    case Low = '1 - Low margin';
    case Standard = '2 - Standard margin';
    case High = '3 - High margin';
    case Unknown = '4 - Unknown margin';
}
