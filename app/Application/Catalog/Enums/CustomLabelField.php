<?php

declare(strict_types=1);

namespace App\Application\Catalog\Enums;

/** Each case is written by exactly one drift sync — see CONTEXT.md custom_label ownership list. */
enum CustomLabelField: string
{
    case CreditTier = 'custom_label_0';
    case MarginTier = 'custom_label_1';
    case BestSellers = 'custom_label_4';
}
