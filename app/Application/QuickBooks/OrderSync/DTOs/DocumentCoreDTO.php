<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use DateTimeImmutable;

/**
 * The fields an invoice and a sales receipt share.
 */
final readonly class DocumentCoreDTO
{
    /**
     * @param non-empty-list<GoodsLineDraftDTO> $goodsLines
     */
    public function __construct(
        public string $docNumber,
        public DateTimeImmutable $txnDate,
        public string $billEmail,
        public CustomerDraftDTO $customer,
        public array $goodsLines,
        public ?ShippingLineDraftDTO $shippingLine,
    ) {}
}
