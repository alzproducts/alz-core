<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use RuntimeException;

interface CallTrackingVisitRepositoryInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException If the gateway returns an unexpected insert result (programming error)
     */
    public function save(CallTrackingVisit $visit): Uuid;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If stored data bypasses VO guards
     * @throws RecordNotFoundException
     */
    public function findById(Uuid $id): CallTrackingVisit;

    /**
     * Queries both `gclid` and `msclkid` columns — formats are structurally distinct.
     * Implementations must `SELECT ... FOR UPDATE` so concurrent same-click-id requests
     * serialise inside the caller's outer transaction.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If stored data bypasses VO guards
     */
    public function findRecentByClickId(string $clickId, DateTimeImmutable $after): ?CallTrackingVisit;
}
