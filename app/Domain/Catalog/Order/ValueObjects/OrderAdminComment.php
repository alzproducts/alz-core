<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;

/**
 * Order admin comment value object.
 *
 * Represents an internal note added by staff to an order.
 *
 * Note: external_id stays in Infrastructure layer (debugging only, not business-essential).
 */
final readonly class OrderAdminComment
{
    /**
     * @param string $content Comment text
     * @param DateTimeImmutable|null $createdAt When comment was created in ShopWired
     * @param int|null $statusId Associated ShopWired status ID
     */
    public function __construct(
        public string $content,
        public ?DateTimeImmutable $createdAt = null,
        public ?int $statusId = null,
    ) {}
}
