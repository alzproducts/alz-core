<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

interface InboundCallDispatcherInterface
{
    public function dispatchInboundCallProcessing(
        string $callerPhoneNumber,
        string $trackingNumberDialled,
        string $callSid,
    ): void;
}
