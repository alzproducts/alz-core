<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface CallTrackingCallRepositoryInterface
{
    /**
     * @return string UUID of the created record
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(CallTrackingCall $call): string;
}
