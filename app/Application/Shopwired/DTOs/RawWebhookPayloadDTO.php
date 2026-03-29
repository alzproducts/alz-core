<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use DateTimeImmutable;

/**
 * Raw webhook envelope fields passed from Presentation to Application.
 *
 * Groups the five scalar webhook parameters into a single transport object.
 * The Handle*WebhookService destructures this to resolve intent, parse the
 * topic enum, and delegate to the appropriate use case.
 */
final readonly class RawWebhookPayloadDTO
{
    /**
     * @param array<string, mixed> $data Topic-specific event payload
     */
    public function __construct(
        public DateTimeImmutable $eventTime,
        public int $webhookId,
        public string $topic,
        public int $subjectId,
        public array $data,
    ) {}
}
