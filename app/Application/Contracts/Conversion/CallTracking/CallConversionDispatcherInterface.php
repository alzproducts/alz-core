<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;

interface CallConversionDispatcherInterface
{
    public function dispatchCallLeadConversion(AdPlatform $platform, CallLeadConversionCommand $command): void;
}
