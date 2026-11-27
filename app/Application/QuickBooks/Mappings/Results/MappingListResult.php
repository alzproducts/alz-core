<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Results;

use App\Application\QuickBooks\Mappings\DTOs\MappingStatusDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\RecordNotFoundException;

final readonly class MappingListResult
{
    /**
     * @param list<MappingStatusDTO> $rows every seeded key
     */
    public function __construct(public array $rows) {}

    /**
     * @throws RecordNotFoundException when the key has no row
     */
    public function row(MappingKey $key): MappingStatusDTO
    {
        return \array_find($this->rows, static fn(MappingStatusDTO $row): bool => $row->mapping->key === $key)
            ?? throw new RecordNotFoundException('QuickBooks mapping', $key->value);
    }
}
