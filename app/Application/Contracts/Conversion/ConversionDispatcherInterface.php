<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion;

use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;

interface ConversionDispatcherInterface
{
    public function dispatchLeadConversion(LeadConversionCommand $command): void;

    public function dispatchBingLeadConversion(LeadConversionCommand $command): void;

    public function dispatchQuoteConversion(QuoteConversionCommand $command): void;
}
