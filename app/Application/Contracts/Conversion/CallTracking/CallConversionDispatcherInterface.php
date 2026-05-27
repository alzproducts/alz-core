<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;

interface CallConversionDispatcherInterface
{
    public function dispatchGoogleCallLeadConversion(CallLeadConversionCommand $command): void;

    public function dispatchBingCallLeadConversion(CallLeadConversionCommand $command): void;
}
