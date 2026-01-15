<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Order admin comment value object.
 *
 * Represents an internal note added by staff to an order.
 */
final readonly class OrderAdminComment
{
    /**
     * @param int $externalId ShopWired comment ID
     * @param string $content Comment text
     * @param DateTimeImmutable $createdAt When comment was created in ShopWired
     * @param int|null $statusId Associated ShopWired status ID (status when comment was added)
     */
    public function __construct(
        public int $externalId,
        public string $content,
        public DateTimeImmutable $createdAt,
        public ?int $statusId = null,
    ) {
        Assert::greaterThan($externalId, 0, 'Comment external ID must be positive');
    }
}
