<?php

declare(strict_types=1);

namespace App\Infrastructure\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\CallTracking\Models\CallTrackingCallModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Override;
use stdClass;

final readonly class EloquentCallTrackingQueryRepository implements CallTrackingQueryRepositoryInterface
{
    /**
     * 12 hours so the hourly sweep overlaps itself; late-arriving visits that
     * straddle the previous run still surface as collisions next time.
     */
    private const int LOOKBACK_HOURS = 12;

    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @return list<array{call_id: string, visit_ids: list<string>, tracking_number: string}>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findAttributionCollisions(): array
    {
        $cutoff = CarbonImmutable::now()->subHours(self::LOOKBACK_HOURS);

        $rows = $this->gateway->query(
            static fn(): array => \array_values(
                CallTrackingCallModel::query()
                    ->from('customer_service.call_tracking_calls as calls')
                    ->join(
                        'customer_service.call_tracking_visits as visits',
                        static function (JoinClause $join): void {
                            $join->on('visits.tracking_number_shown', '=', 'calls.tracking_number_dialled')
                                ->whereColumn('calls.created_at', '>=', 'visits.created_at')
                                ->whereRaw("calls.created_at < visits.created_at + INTERVAL '6 hours'");
                        },
                    )
                    ->where('calls.created_at', '>=', $cutoff)
                    ->orderBy('calls.id')
                    ->toBase()
                    ->get([
                        'calls.id as call_id',
                        'calls.tracking_number_dialled as tracking_number',
                        'visits.id as visit_id',
                    ])
                    ->all(),
            ),
        );

        return self::filterToCollisions(self::buildCollisionMap($rows));
    }

    /**
     * @param list<stdClass> $rows Raw join-multiplied rows, one per (call, visit) match.
     *
     * @return array<string, array{call_id: string, visit_ids: list<string>, tracking_number: string}>
     */
    private static function buildCollisionMap(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            /** @var string $callId */
            $callId = $row->call_id;
            /** @var string $visitId */
            $visitId = $row->visit_id;
            /** @var string $trackingNumber */
            $trackingNumber = $row->tracking_number;

            $map[$callId] ??= [
                'call_id' => $callId,
                'visit_ids' => [],
                'tracking_number' => $trackingNumber,
            ];

            $map[$callId]['visit_ids'][] = $visitId;
        }

        return $map;
    }

    /**
     * @param array<string, array{call_id: string, visit_ids: list<string>, tracking_number: string}> $map
     *
     * @return list<array{call_id: string, visit_ids: list<string>, tracking_number: string}>
     */
    private static function filterToCollisions(array $map): array
    {
        return \array_values(\array_filter(
            $map,
            static fn(array $collision): bool => \count($collision['visit_ids']) > 1,
        ));
    }
}
