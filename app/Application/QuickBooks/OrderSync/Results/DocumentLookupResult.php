<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;

/**
 * Every document QBO holds under the requested numbers, matched ignoring case as QBO compares them.
 */
final readonly class DocumentLookupResult
{
    /**
     * @param list<ExistingDocumentDTO> $documents
     */
    public function __construct(
        public array $documents,
    ) {}

    /**
     * @return list<ExistingDocumentDTO> empty when QBO holds none; one number can match several documents
     */
    public function forDocNumber(string $docNumber): array
    {
        $key = self::key($docNumber);

        return \array_values(\array_filter(
            $this->documents,
            static fn(ExistingDocumentDTO $document): bool => self::key($document->docNumber) === $key,
        ));
    }

    private static function key(string $docNumber): string
    {
        return \mb_strtolower(\mb_trim($docNumber));
    }
}
