<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

final readonly class ReadyOrderDTO
{
    public function __construct(
        public ClaimedOrderDTO $order,
        public PostableDocumentDTO $document,
    ) {}
}
