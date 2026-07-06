<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion;

use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;

interface ConversionDispatcherInterface
{
    public function dispatchLeadConversion(AdPlatform $platform, LeadConversionCommand $command): void;

    public function dispatchQuoteConversion(QuoteConversionCommand $command): void;
}
