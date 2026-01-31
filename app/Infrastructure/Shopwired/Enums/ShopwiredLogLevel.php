<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Logging verbosity levels for ShopWired API transport.
 *
 * - Info: Log endpoint, status, duration (for monitoring)
 * - Debug: Log full request/response bodies (for debugging)
 */
enum ShopwiredLogLevel: string
{
    case Info = 'info';
    case Debug = 'debug';
}
