<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Infrastructure\Shopwired\Enums\WebhookSubjectType;
use App\Infrastructure\Shopwired\Enums\WebhookTopic;
use Spatie\LaravelData\Data;

/**
 * The `event` object nested within a ShopWired webhook payload.
 *
 * Contains the event metadata (topic, subject) and the data payload.
 * For standard events, `data` contains `{object: {full entity}}`.
 * For custom events, `data` contains a lightweight payload specific to the topic.
 * For delete events, `data` contains `{object: {full entity}}`.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
final class WebhookEventPayloadResponse extends Data
{
    /**
     * @param int $id Webhook event ID (for logging/debugging)
     * @param string $createdAt ISO 8601 timestamp of event creation
     * @param WebhookTopic $topic Event topic (auto-cast from string by Spatie Data)
     * @param WebhookSubjectType $subjectType Subject type (auto-cast from string by Spatie Data)
     * @param int $subjectId The ID of the affected entity in ShopWired
     * @param array<string, mixed> $data Event payload — structure varies by topic
     */
    public function __construct(
        public readonly int $id,
        public readonly string $createdAt,
        public readonly WebhookTopic $topic,
        public readonly WebhookSubjectType $subjectType,
        public readonly int $subjectId,
        public readonly array $data,
    ) {}
}
