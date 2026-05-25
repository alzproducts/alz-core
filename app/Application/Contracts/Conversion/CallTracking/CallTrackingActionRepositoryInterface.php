<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface CallTrackingActionRepositoryInterface
{
    /**
     * Partial unique index `(call_tracking_visit_id, ad_platform)` prevents duplicates.
     *
     * @return string UUID of the created row
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function create(string $visitId, AdPlatform $platform): string;
}
