<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Enums;

/**
 * Queue priority tiers for job routing via Horizon.
 *
 * @see config/horizon.php For queue worker configuration
 */
enum QueueName: string
{
    case High = 'high';
    case Default = 'default';
    case Low = 'low';
    case Bulk = 'bulk';
    case Background = 'background';
}
