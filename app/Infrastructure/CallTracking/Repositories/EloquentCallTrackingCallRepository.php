<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\CallTracking\Mappers\CallTrackingCallMapper;
use App\Infrastructure\CallTracking\Models\CallTrackingCallModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

final readonly class EloquentCallTrackingCallRepository implements CallTrackingCallRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function saveOrIgnore(CallTrackingCall $call): void
    {
        $this->gateway->insertOrIgnore(
            CallTrackingCallModel::class,
            CallTrackingCallMapper::toModelAttributes($call),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function isFullyProcessed(string $callSid): bool
    {
        return $this->gateway->query(
            static fn(): bool => CallTrackingCallModel::query()
                ->where('call_sid', $callSid)
                ->whereNotNull('helpscout_conversation_id')
                ->exists(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function setHelpScoutConversationIdByCallSid(string $callSid, IntId $conversationId): void
    {
        $this->gateway->updateWhere(
            CallTrackingCallModel::class,
            'call_sid',
            $callSid,
            ['helpscout_conversation_id' => $conversationId->value],
        );
    }

    /**
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findById(Uuid $id): CallTrackingCall
    {
        /** @var CallTrackingCall */
        return $this->gateway->findOrFail(
            CallTrackingCallModel::class,
            'id',
            $id->value,
            entityTypeName: 'CallTrackingCall',
            mapper: static fn(CallTrackingCallModel $m): CallTrackingCall => $m->toDomain(),
        );
    }
}
