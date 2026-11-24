<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;

interface OrderTotalsQueryRepositoryInterface
{
    /**
     * Every order placed at or after `from` and before `before`, earliest first.
     *
     * @return list<SourceOrderTotalsDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function placedIn(DateTimeImmutable $from, DateTimeImmutable $before): array;
}
