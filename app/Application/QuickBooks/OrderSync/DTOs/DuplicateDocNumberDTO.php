<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * An order's document number that QBO holds on more than one document.
 */
final readonly class DuplicateDocNumberDTO
{
    /**
     * @param string                    $docNumber the order's number, whatever case QBO stores it in
     * @param list<ExistingDocumentDTO> $documents two or more
     */
    public function __construct(
        public string $docNumber,
        public array $documents,
    ) {}
}
