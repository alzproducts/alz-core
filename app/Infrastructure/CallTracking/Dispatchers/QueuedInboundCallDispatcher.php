<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Dispatchers;

use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Application\Conversion\CallTracking\UseCases\ProcessInboundCallUseCase;
use App\Infrastructure\Jobs\CallTracking\ProcessInboundCallJob;
use Illuminate\Support\Str;
use Override;

/**
 * Pre-generates the call UUID before dispatch so that the job (and its
 * retries) all converge on the same `call_tracking_calls` row, which is
 * what makes {@see ProcessInboundCallUseCase}
 * idempotent across attempts.
 */
final readonly class QueuedInboundCallDispatcher implements InboundCallDispatcherInterface
{
    #[Override]
    public function dispatchInboundCallProcessing(
        string $callerPhoneNumber,
        string $trackingNumberDialled,
        string $callSid,
    ): void {
        ProcessInboundCallJob::dispatch(
            Str::uuid()->toString(),
            $callerPhoneNumber,
            $trackingNumberDialled,
            $callSid,
        );
    }
}
