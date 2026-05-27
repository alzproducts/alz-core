<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Domain\Conversion\CallTracking\Exceptions\AmbiguousCallAttributionException;
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
use Illuminate\Database\Query\JoinClause;
use Override;
use RuntimeException;

final readonly class EloquentCallTrackingVisitRepository implements CallTrackingVisitRepositoryInterface
{
    /**
     * Columns scanned when reusing a visit for a returning click-ID visitor.
     * Extend here when adding a new ad-platform click ID (e.g. fbclid).
     */
    private const array CLICK_ID_COLUMNS = ['gclid', 'msclkid'];

    public function __construct(
        private EloquentGateway $gateway,
        private int $attributionWindowHours,
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
                    foreach (self::CLICK_ID_COLUMNS as $column) {
                        $q->orWhere($column, $clickId);
                    }
                })
                ->where('created_at', '>', $after)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            return $model?->toDomain();
        });
    }

    /**
     * @throws RecordNotFoundException When no unique visit matches the call
     * @throws AmbiguousCallAttributionException When more than one visit matches within the attribution window
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If stored data bypasses VO guards
     */
    #[Override]
    public function findByCallId(Uuid $callId): CallTrackingVisit
    {
        $matches = $this->fetchVisitsMatchingCall($callId);

        return self::ensureUniqueMatch($matches, $callId)->toDomain();
    }

    /**
     * @return list<CallTrackingVisitModel>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function fetchVisitsMatchingCall(Uuid $callId): array
    {
        $windowHours = $this->attributionWindowHours;

        /** @var list<CallTrackingVisitModel> */
        return $this->gateway->query(
            static fn(): array => CallTrackingVisitModel::query()
                ->select('customer_service.call_tracking_visits.*')
                ->join(
                    'customer_service.call_tracking_calls',
                    static fn(JoinClause $join) => self::applyTemporalTrackingNumberJoin($join, $windowHours),
                )
                ->where('customer_service.call_tracking_calls.id', $callId->value)
                ->limit(2) // only need 2 to detect ambiguity
                ->get()
                ->all(),
        );
    }

    /**
     * Must match the predicate shape in `marketing.potential_conversions_view` so a
     * dashboard row's attribution and this repository's resolution agree. The view
     * uses a literal `INTERVAL '6 hours'`; if `call-tracking.attribution_window_hours`
     * is overridden away from 6, the two resolve different visits.
     */
    private static function applyTemporalTrackingNumberJoin(JoinClause $join, int $windowHours): JoinClause
    {
        return $join
            ->on(
                'customer_service.call_tracking_calls.tracking_number_dialled',
                '=',
                'customer_service.call_tracking_visits.tracking_number_shown',
            )
            ->whereColumn(
                'customer_service.call_tracking_calls.created_at',
                '>=',
                'customer_service.call_tracking_visits.created_at',
            )
            ->whereRaw(
                "customer_service.call_tracking_calls.created_at < customer_service.call_tracking_visits.created_at + (?::text || ' hours')::interval",
                [$windowHours],
            );
    }

    /**
     * @param list<CallTrackingVisitModel> $matches
     *
     * @throws RecordNotFoundException
     * @throws AmbiguousCallAttributionException
     */
    private static function ensureUniqueMatch(array $matches, Uuid $callId): CallTrackingVisitModel
    {
        if ($matches === []) {
            throw new RecordNotFoundException('CallTrackingVisit', $callId->value);
        }

        if (\count($matches) > 1) {
            throw new AmbiguousCallAttributionException($callId->value);
        }

        return $matches[0];
    }
}
