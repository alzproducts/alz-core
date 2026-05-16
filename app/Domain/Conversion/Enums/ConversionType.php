<?php

declare(strict_types=1);

namespace App\Domain\Conversion\Enums;

/**
 * Types of offline business conversions attributed back to advertising clicks.
 *
 * Used by Application/Infrastructure layers to identify which conversion
 * action (in the ad platform's configuration) an upload should target.
 */
enum ConversionType: string
{
    case LeadReceived = 'lead_received';
    case QuoteIssued = 'quote_issued';
}
