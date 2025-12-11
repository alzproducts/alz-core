<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Snooze information for a conversation.
 *
 * This field is critical - it's dropped by the SDK during hydration,
 * which is why we use direct HTTP instead.
 */
final class Snooze extends Data
{
    public function __construct(
        public readonly ?int $snoozedBy,
        public readonly ?string $snoozedUntil,
        public readonly ?bool $unsnoozeOnCustomerReply,
    ) {}
}
