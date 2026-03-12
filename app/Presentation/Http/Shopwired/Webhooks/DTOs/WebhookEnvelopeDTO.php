<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\Webhooks\DTOs;

use DateTimeImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
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
        #[WithCast(DateTimeInterfaceCast::class, format: ['D, d M Y H:i:s O', 'Y-m-d\TH:i:sP'])]
        public readonly DateTimeImmutable $timestamp,
        public readonly WebhookEventPayloadDTO $event,
    ) {}
}
