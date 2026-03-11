<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\Webhooks\DTOs;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Outer envelope for all ShopWired webhook payloads.
 *
 * Validates the shared root structure only.
 * Topic-specific routing is handled by handler services in the Application layer.
 */
final class WebhookEnvelopeDTO extends Data
{
    public function __construct(
        public readonly DateTimeImmutable $timestamp,
        public readonly WebhookEventPayloadDTO $event,
    ) {}
}
