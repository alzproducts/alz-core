<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

/**
 * Represents a webhook registered with ShopWired.
 *
 * Returned by the webhooks API endpoint. Used by CheckShopwiredWebhookHealthJob
 * to detect disabled or unverified webhooks before they cause silent data sync gaps.
 */
final readonly class WebhookDTO
{
    public function __construct(
        public int $id,
        public string $topic,
        public string $address,
        public bool $enabled,
        public bool $verified,
    ) {}

    public function isHealthy(): bool
    {
        return $this->enabled && $this->verified;
    }
}
