<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Enums;

enum BodyEncoding
{
    case RequestWrapped;
    case Json;
    case FormParams;
}
