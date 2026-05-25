<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;

interface CallTrackingVisitRepositoryInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(CallTrackingVisit $visit): Uuid;

    /**
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function findById(Uuid $id): CallTrackingVisit;

    /**
     * Queries both `gclid` and `msclkid` columns — formats are structurally distinct.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function findRecentByClickId(string $clickId, DateTimeImmutable $after): ?CallTrackingVisit;
}
