<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

enum ThirdPartyService: string
{
    case ClickUp = 'clickup';
    case HelpScout = 'helpscout';
}
