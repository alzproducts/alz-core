<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Conversion\CallTracking\Models\CallTrackingVisitModel;
use App\Infrastructure\Persistence\EloquentGateway;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final readonly class EloquentCallTrackingVisitRepository implements CallTrackingVisitRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException If fillForInsert returns unexpected result (programming error)
     */
    public function save(CallTrackingVisit $visit): Uuid
    {
        $id = $this->gateway->insertOne(
            CallTrackingVisitModel::class,
            CallTrackingVisitModel::fromDomainAttributes($visit),
        );

        return Uuid::fromTrusted($id);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If stored data bypasses VO guards
     * @throws RecordNotFoundException
     */
    public function findById(Uuid $id): CallTrackingVisit
    {
        /** @var CallTrackingVisit */
        return $this->gateway->findOrFail(
            CallTrackingVisitModel::class,
            'id',
            $id->value,
            entityTypeName: 'CallTrackingVisit',
            mapper: static fn(CallTrackingVisitModel $m): CallTrackingVisit => $m->toDomain(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If stored data bypasses VO guards
     */
    public function findRecentByClickId(string $clickId, DateTimeImmutable $after): ?CallTrackingVisit
    {
        /** @var CallTrackingVisit|null */
        return $this->gateway->query(static function () use ($clickId, $after): ?CallTrackingVisit {
            $model = CallTrackingVisitModel::query()
                ->where(static function (Builder $q) use ($clickId): void {
                    $q->where('gclid', $clickId)->orWhere('msclkid', $clickId);
                })
                ->where('created_at', '>', $after)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            return $model?->toDomain();
        });
    }
}
