<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\DuplicateDocNumberDTO;

final readonly class DuplicateDocNumbersResult
{
    /**
     * @param list<DuplicateDocNumberDTO> $duplicates
     */
    public function __construct(
        public int $ordersChecked,
        public array $duplicates,
    ) {}

    /**
     * @return array<string, int> orders_checked, duplicate_doc_numbers and duplicate_documents, zeros included
     */
    public function counts(): array
    {
        return [
            'orders_checked' => $this->ordersChecked,
            'duplicate_doc_numbers' => \count($this->duplicates),
            'duplicate_documents' => \array_sum(\array_map(
                static fn(DuplicateDocNumberDTO $duplicate): int => \count($duplicate->documents),
                $this->duplicates,
            )),
        ];
    }
}
