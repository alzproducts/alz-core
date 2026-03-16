<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\ValueObjects\IntId;

/**
 * Result from parsing an order refund webhook payload.
 *
 * Carries the parsed refund alongside the extracted order ID,
 * since refund webhooks use the refund ID as subjectId — the
 * actual order ID must be extracted from the payload body.
 */
final readonly class WebhookOrderRefundResultDTO
{
    public function __construct(
        public IntId $orderId,
        public OrderRefund $refund,
    ) {}
}
