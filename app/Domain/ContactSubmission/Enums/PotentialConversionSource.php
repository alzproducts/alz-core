<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

enum PotentialConversionSource: string
{
    case Form = 'form';
    case Call = 'call';
}
