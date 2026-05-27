<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Dispatchers;

use App\Application\Contracts\Conversion\CallTracking\CallConversionDispatcherInterface;
use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;
use App\Infrastructure\Jobs\Conversion\CallTracking\ProcessBingCallLeadConversionJob;
use App\Infrastructure\Jobs\Conversion\CallTracking\ProcessCallLeadConversionJob;
use Override;

/**
 * Domain types in the command are unwrapped to primitive scalars for queue serialisation.
 */
final readonly class QueuedCallConversionDispatcher implements CallConversionDispatcherInterface
{
    #[Override]
    public function dispatchCallLeadConversion(CallLeadConversionCommand $command): void
    {
        ProcessCallLeadConversionJob::dispatch(
            $command->visitId->value,
            $command->actionId->value,
            $command->callerPhone->value,
        );
    }

    #[Override]
    public function dispatchBingCallLeadConversion(CallLeadConversionCommand $command): void
    {
        ProcessBingCallLeadConversionJob::dispatch(
            $command->visitId->value,
            $command->actionId->value,
            $command->callerPhone->value,
        );
    }
}
