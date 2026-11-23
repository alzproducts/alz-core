<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface OrderSyncSourceQueryRepositoryInterface
{
    /**
     * Keyed by reference; a reference no order has is omitted.
     *
     * @param list<int> $references
     *
     * @return array<int, SourceOrderRowDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByReferences(array $references): array;
}
