<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRunStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncRunModel;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;
use Override;

/**
 * Every tally and count write is one UPDATE whose right-hand sides read the row as it was, so parallel job ends
 * never lose a count and exactly one of them sees the tally reach zero.
 */
final readonly class EloquentOrderSyncRunRepository implements OrderSyncRunRepositoryInterface
{
    private const string DATE = 'Y-m-d';

    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function record(RequestedOrderSyncRunDTO $run): void
    {
        $this->eloquentGateway->insertMany(OrderSyncRunModel::class, [[
            'id' => $run->id,
            'trigger' => $run->trigger->value,
            'force_retry' => $run->forceRetry,
            'range_from' => $run->range?->from->format(self::DATE),
            'range_to' => $run->range?->to->format(self::DATE),
            'order_references' => $run->references === null ? null : '{' . \implode(',', $run->references) . '}',
            'status' => OrderSyncRunStatus::Queued->value,
            'candidate_count' => $run->candidateCount,
            'requested_at' => $run->requestedAt->format('Y-m-d H:i:s.uP'),
        ]]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function addOpenJob(?string $runId): void
    {
        $this->updateOpen($runId, ['open_jobs' => 1]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function removeOpenJob(?string $runId): void
    {
        $this->updateOpen($runId, ['open_jobs' => -1]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markStarted(?string $runId): void
    {
        if (!self::isRunId($runId)) {
            return;
        }

        $this->eloquentGateway->updateWhereAll(
            OrderSyncRunModel::class,
            ['id' => $runId, 'status' => OrderSyncRunStatus::Queued->value],
            ['status' => OrderSyncRunStatus::Running->value, 'started_at' => new Expression('now()')],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function endJob(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void
    {
        $this->updateOpen($runId, [...self::counts($counts), 'open_jobs' => -1], [...self::marked($failed), ...self::ended($failed)]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function endRangeJob(?string $runId, int $candidateCount): void
    {
        $this->updateOpen($runId, ['open_jobs' => -1], ['candidate_count' => $candidateCount, ...self::ended(false)]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function addCounts(?string $runId, OrderSyncRunCountsDTO $counts, bool $failed): void
    {
        $this->updateOpen($runId, self::counts($counts), self::marked($failed));
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function failRequest(string $runId): void
    {
        $this->updateOpen($runId, [], [
            'job_failed' => true,
            'status' => OrderSyncRunStatus::Failed->value,
            'finished_at' => new Expression('now()'),
        ]);
    }

    /**
     * A run id from before the run table, or none, has no row to write.
     *
     * @phpstan-assert-if-true string $runId
     */
    private static function isRunId(?string $runId): bool
    {
        return $runId !== null && Str::isUuid($runId);
    }

    /**
     * @return array<string, int>
     */
    private static function counts(OrderSyncRunCountsDTO $counts): array
    {
        return [
            'synced' => $counts->synced,
            'already_synced' => $counts->alreadySynced,
            'skipped' => $counts->skipped,
            'failed' => $counts->failed,
            'not_attempted' => $counts->notAttempted,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private static function marked(bool $failed): array
    {
        return $failed ? ['job_failed' => true] : [];
    }

    /**
     * Reads `open_jobs` and `job_failed` as they were before this update, so `open_jobs > 1` means other jobs remain.
     *
     * @return array<string, mixed>
     */
    private static function ended(bool $failed): array
    {
        return [
            'status' => new Expression($failed
                ? "CASE WHEN open_jobs > 1 THEN 'running' ELSE 'failed' END"
                : "CASE WHEN open_jobs > 1 THEN 'running' WHEN job_failed THEN 'failed' ELSE 'completed' END"),
            'finished_at' => new Expression('CASE WHEN open_jobs > 1 THEN NULL ELSE now() END'),
        ];
    }

    /**
     * A finished run never changes again.
     *
     * @param array<string, int> $increments
     * @param array<string, mixed> $set
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function updateOpen(?string $runId, array $increments, array $set = []): void
    {
        if (!self::isRunId($runId)) {
            return;
        }

        $this->eloquentGateway->transact(
            static fn(): int => OrderSyncRunModel::query()
                ->whereKey($runId)
                ->where('finished_at', null)
                ->incrementEach($increments, $set),
        );
    }
}
