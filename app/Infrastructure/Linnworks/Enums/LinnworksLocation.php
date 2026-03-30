<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Enums;

/**
 * Known Linnworks warehouse location GUIDs.
 *
 * The Default location (all-zeros GUID) is Linnworks' sentinel value for the
 * primary/default warehouse. Pass Guid::fromTrusted(LinnworksLocation::Default->value)
 * to filter dashboard queries to the default location only.
 *
 * @template-pattern Infrastructure Enum
 */
enum LinnworksLocation: string
{
    case Default = '00000000-0000-0000-0000-000000000000';
}
