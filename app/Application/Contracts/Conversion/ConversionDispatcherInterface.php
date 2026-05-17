<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion;

use App\Application\Conversion\Commands\LeadConversionCommand;

/**
 * Dispatch async offline-conversion processing.
 *
 * Application layer uses this to trigger uploads without knowing the delivery
 * mechanism (queue, inline, etc.) or which ad platform receives the conversion.
 */
interface ConversionDispatcherInterface
{
    public function dispatchLeadConversion(LeadConversionCommand $command): void;
}
