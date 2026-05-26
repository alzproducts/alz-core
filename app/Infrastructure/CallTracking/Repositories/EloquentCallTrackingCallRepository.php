<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingCall;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\CallTracking\Mappers\CallTrackingCallMapper;
use App\Infrastructure\CallTracking\Models\CallTrackingCallModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;
use RuntimeException;

final readonly class EloquentCallTrackingCallRepository implements CallTrackingCallRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException When EloquentGateway fillForInsert returns unexpected result
     */
    #[Override]
    public function save(CallTrackingCall $call): Uuid
    {
        $id = $this->gateway->insertOne(
            CallTrackingCallModel::class,
            CallTrackingCallMapper::toModelAttributes($call),
        );

        return Uuid::fromTrusted($id);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws MalformedStoredDataException If stored phone numbers fail E.164 validation
     */
    #[Override]
    public function findById(Uuid $id): ?CallTrackingCall
    {
        $model = $this->gateway->query(
            static fn(): ?CallTrackingCallModel => CallTrackingCallModel::query()
                ->where('id', $id->value)
                ->first(),
        );

        return $model !== null ? CallTrackingCallMapper::fromModel($model) : null;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function setHelpScoutConversationId(Uuid $callId, IntId $conversationId): void
    {
        $this->gateway->updateWhere(
            CallTrackingCallModel::class,
            'id',
            $callId->value,
            ['helpscout_conversation_id' => $conversationId->value],
        );
    }
}
