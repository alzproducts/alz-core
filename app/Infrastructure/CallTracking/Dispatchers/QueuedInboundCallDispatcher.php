<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Dispatchers;

use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Infrastructure\Jobs\CallTracking\ProcessInboundCallJob;
use Override;

final readonly class QueuedInboundCallDispatcher implements InboundCallDispatcherInterface
{
    #[Override]
    public function dispatchInboundCallProcessing(
        string $callerPhoneNumber,
        string $trackingNumberDialled,
        string $callSid,
    ): void {
        ProcessInboundCallJob::dispatch(
            $callSid,
            $callerPhoneNumber,
            $trackingNumberDialled,
        );
    }
}
