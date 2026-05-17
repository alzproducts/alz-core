<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\Dispatchers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Infrastructure\Jobs\Conversion\ProcessLeadConversionJob;
use Override;

/**
 * Queue-backed dispatcher for offline conversion processing.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedConversionDispatcher implements ConversionDispatcherInterface
{
    #[Override]
    public function dispatchLeadConversion(LeadConversionCommand $command): void
    {
        ProcessLeadConversionJob::dispatch($command->submissionId, $command->actionId);
    }
}
