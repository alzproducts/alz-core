<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;

/**
 * An invoice or sales receipt QBO already holds, its number as QBO stores it.
 */
final readonly class ExistingDocumentDTO
{
    /**
     * @param non-empty-string $qboId
     */
    public function __construct(
        public string $docNumber,
        public string $qboId,
        public DocType $type,
    ) {}
}
