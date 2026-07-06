<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\Dispatchers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
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
    public function dispatchLeadConversion(AdPlatform $platform, LeadConversionCommand $command): void
    {
        match ($platform) {
            AdPlatform::Google => ProcessLeadConversionJob::dispatch($command->submissionId->value, $command->actionId->value),
            AdPlatform::Bing => ProcessBingLeadConversionJob::dispatch($command->submissionId->value, $command->actionId->value),
        };
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
