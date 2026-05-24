<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\Dispatchers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Infrastructure\Jobs\Conversion\ProcessBingLeadConversionJob;
use App\Infrastructure\Jobs\Conversion\ProcessLeadConversionJob;
use App\Infrastructure\Jobs\Conversion\ProcessQuoteConversionJob;
use DateTimeInterface;
use Override;

/**
 * Domain types in the command are unwrapped to primitive scalars for queue serialisation.
 */
final readonly class QueuedConversionDispatcher implements ConversionDispatcherInterface
{
    #[Override]
    public function dispatchLeadConversion(LeadConversionCommand $command): void
    {
        ProcessLeadConversionJob::dispatch($command->submissionId->value, $command->actionId->value);
    }

    #[Override]
    public function dispatchBingLeadConversion(LeadConversionCommand $command): void
    {
        ProcessBingLeadConversionJob::dispatch($command->submissionId->value, $command->actionId->value);
    }

    #[Override]
    public function dispatchQuoteConversion(QuoteConversionCommand $command): void
    {
        ProcessQuoteConversionJob::dispatch(
            $command->submissionId->value,
            $command->actionId->value,
            $command->value->toNet(),
            $command->convertedAt->format(DateTimeInterface::ATOM),
        );
    }
}
