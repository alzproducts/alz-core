<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Enums;

enum ExternalIdScope: string
{
    case Product = 'product';
    case Variation = 'variation';
}
