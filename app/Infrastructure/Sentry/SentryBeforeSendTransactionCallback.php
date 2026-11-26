<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Transactions skip `before_send`, so the URL query scrub needs its own hook.
 */
final class SentryBeforeSendTransactionCallback
{
    /**
     * Required for config:cache serialization (var_export).
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }

    public function __invoke(Event $event, ?EventHint $hint): Event
    {
        return SentryEventScrubber::scrub($event);
    }
}
