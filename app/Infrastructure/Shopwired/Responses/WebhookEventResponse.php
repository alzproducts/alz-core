<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Data;

/**
 * Root structure of a ShopWired webhook payload.
 *
 * ShopWired sends webhooks as JSON with two root fields:
 * - `timestamp`: ISO 8601 timestamp of when the event was dispatched
 * - `event`: nested object containing the event details
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
final class WebhookEventResponse extends Data
{
    public function __construct(
        public readonly string $timestamp,
        public readonly WebhookEventPayloadResponse $event,
    ) {}
}
