<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

interface CallTrackingCallRepositoryInterface
{
    /**
     * Persist a call record, silently ignoring duplicates (keyed on call_sid).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveOrIgnore(CallTrackingCall $call): void;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function isFullyProcessed(string $callSid): bool;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function setHelpScoutConversationIdByCallSid(string $callSid, IntId $conversationId): void;
}
