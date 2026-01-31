<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Enums;

/**
 * Logging verbosity levels for Mixpanel API transport.
 *
 * - Info: Log endpoint, status, duration (for monitoring)
 * - Debug: Log full request/response bodies (for debugging)
 */
enum MixpanelLogLevel: string
{
    case Info = 'info';
    case Debug = 'debug';
}
