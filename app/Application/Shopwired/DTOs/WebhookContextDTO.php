<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Application\Shopwired\Enums\WebhookTopic;
use DateTimeImmutable;

/**
 * Parsed webhook context passed from Handle services to Use Cases.
 *
 * Replaces the (eventTime, webhookId, topic) parameter triplet that repeats
 * across all webhook use case signatures. Unlike {@see RawWebhookPayloadDTO},
 * the topic is a parsed enum — Handle services perform string→enum conversion.
 */
final readonly class WebhookContextDTO
{
    public function __construct(
        public DateTimeImmutable $eventTime,
        public int $webhookId,
        public WebhookTopic $topic,
    ) {}
}
