<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface CallTrackingQueryRepositoryInterface
{
    /**
     * Find calls in the last 12 hours that match more than one visit inside the
     * 6-hour attribution window. The view silently excludes these rows; the
     * collision-detection use case alerts on them so visitor data quality can
     * be investigated.
     *
     * @return list<array{call_id: string, visit_ids: list<string>, tracking_number: string}>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findAttributionCollisions(): array;
}
