<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Enums;

/**
 * Logging verbosity levels for Linnworks API transport.
 *
 * - Info: Log endpoint, status, duration (for monitoring)
 * - Debug: Log full request/response bodies (for debugging)
 */
enum LinnworksLogLevel: string
{
    case Info = 'info';
    case Debug = 'debug';
}
