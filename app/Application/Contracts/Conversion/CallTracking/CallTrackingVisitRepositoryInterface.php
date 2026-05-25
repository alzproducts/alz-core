<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;

interface CallTrackingVisitRepositoryInterface
{
    /**
     * @return string UUID of the created record
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(CallTrackingVisit $visit): string;

    /**
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function findById(string $id): CallTrackingVisit;

    /**
     * `AdPlatform::Google` queries `gclid`; `AdPlatform::Bing` queries `msclkid`.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function findRecentByClickId(string $clickId, AdPlatform $platform, DateTimeImmutable $after): ?CallTrackingVisit;
}
