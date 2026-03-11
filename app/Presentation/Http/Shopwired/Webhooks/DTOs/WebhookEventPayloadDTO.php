<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\Webhooks\DTOs;

use Spatie\LaravelData\Data;

/**
 * The `event` object nested within a ShopWired webhook payload.
 *
 * Topic is kept as a plain string to avoid Presentation → Infrastructure
 * dependency on the WebhookTopic enum. Topic routing is delegated to the
 * Application handler service via the resolver interface.
 */
final class WebhookEventPayloadDTO extends Data
{
    /**
     * @param array<string, mixed> $data Event payload — structure varies by topic
     */
    public function __construct(
        public readonly int $id,
        public readonly string $topic,
        public readonly int $subjectId,
        public readonly array $data,
    ) {}
}
