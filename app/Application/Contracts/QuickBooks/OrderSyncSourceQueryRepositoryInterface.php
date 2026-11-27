<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CatchUpCandidateWindowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;

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

    /**
     * How many references were placed from `$from` up to but not including `$before`, whatever their status or sync row.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function countPlacedIn(DateTimeImmutable $from, DateTimeImmutable $before): int;

    /**
     * The references {@see countPlacedIn()} counts.
     *
     * @return list<int> distinct, ascending
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function referencesPlacedIn(DateTimeImmutable $from, DateTimeImmutable $before): array;

    /**
     * Orders not cancelled, placed in the window, with no sync row for the realm, a row neither `synced` nor
     * `failed`, or a `failed` `item_missing` row placed from the window's `$itemMissingSince`.
     *
     * @return list<int> distinct, ascending
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function catchUpCandidates(CatchUpCandidateWindowDTO $window): array;
}
