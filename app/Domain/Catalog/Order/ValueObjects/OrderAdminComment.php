<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Order admin comment value object.
 *
 * Represents an internal note added by staff to an order. Contains only
 * business-essential fields - infrastructure details (external_id, timestamps)
 * stay in the Infrastructure layer.
 */
final readonly class OrderAdminComment
{
    public function __construct(
        public string $content,
        public ?int $statusId = null,
    ) {}
}
