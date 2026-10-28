<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Repositories;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\ContactSubmission\DTOs\RecoverableActionDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionActionModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class EloquentContactSubmissionActionRepository implements ContactSubmissionActionRepositoryInterface
{
    private const int QUOTE_VALUE_SCALE = 6;

    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException
     */
    public function create(string $submissionId, ActionType $actionType, ?AdPlatform $adPlatform = null): string
    {
        return $this->gateway->insertOne(
            ContactSubmissionActionModel::class,
            [
                'contact_submission_id' => $submissionId,
                'action_type' => $actionType,
                'ad_platform' => $adPlatform,
                'status' => ActionStatus::Pending,
                'attempts' => 0,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException
     */
    public function createQuoteAction(string $submissionId, AdPlatform $platform, Money $value, DateTimeImmutable $convertedAt): string
    {
        return $this->gateway->insertOne(
            ContactSubmissionActionModel::class,
            [
                'contact_submission_id' => $submissionId,
                'action_type' => ActionType::QuoteIssued,
                'ad_platform' => $platform,
                'status' => ActionStatus::Pending,
                'attempts' => 0,
                'quote_value_net' => $value->formatNet(self::QUOTE_VALUE_SCALE),
                // intentional: the datetime cast drops the offset, so store in the app (UTC) zone
                'quote_converted_at' => CarbonImmutable::instance($convertedAt)->utc(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findActionType(string $actionId): ?ActionType
    {
        return $this->gateway->query(
            static function () use ($actionId): ?ActionType {
                /** @var ContactSubmissionActionModel|null $model */
                $model = ContactSubmissionActionModel::query()
                    ->where('id', $actionId)
                    ->first(['action_type']);

                return $model?->action_type;
            },
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(string $actionId): ?ActionClaimDTO
    {
        $token = Str::uuid()->toString();

        if (! $this->claimDuePending($actionId, $token)) {
            return null;
        }

        return $this->gateway->query(
            static fn(): ?ActionClaimDTO => ContactSubmissionActionModel::query()
                ->where('id', $actionId)
                ->where('claim_token', $token)
                ->first(['attempts', 'external_id', 'created_at'])
                ?->toActionClaim($token),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function completeClaim(string $actionId, string $claimToken, string $externalId): bool
    {
        return $this->updateClaimed($actionId, $claimToken, [
            'status' => ActionStatus::Completed,
            'external_id' => $externalId,
            'completed_at' => new Expression('now()'),
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failClaim(string $actionId, string $claimToken, string $errorMessage): bool
    {
        return $this->updateClaimed($actionId, $claimToken, [
            'status' => ActionStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function returnToPending(string $actionId, string $claimToken, int $delaySeconds): bool
    {
        return $this->updateClaimed($actionId, $claimToken, [
            'status' => ActionStatus::Pending,
            'next_attempt_at' => CarbonImmutable::now()->addSeconds($delaySeconds),
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failUnclaimed(string $actionId, string $errorMessage): bool
    {
        return $this->gateway->updateWhereAll(
            ContactSubmissionActionModel::class,
            ['id' => $actionId, 'status' => ActionStatus::Pending->value],
            ['status' => ActionStatus::Failed, 'error_message' => $errorMessage],
        ) > 0;
    }

    /**
     * @param list<string> $conversationIds
     *
     * @return list<string>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function linkedConversationIds(array $conversationIds, string $excludeActionId): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return $this->gateway->query(
            /** @return list<string> */
            static fn(): array => \array_values(ContactSubmissionActionModel::query()
                ->where('action_type', ActionType::HelpScout)
                ->where('id', '!=', $excludeActionId)
                ->whereIn('external_id', $conversationIds)
                ->distinct()
                ->pluck('external_id')
                ->filter(static fn(mixed $id): bool => \is_string($id))
                ->all()),
        );
    }

    /**
     * @return list<RecoverableActionDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findExpiredLeases(DateTimeImmutable $claimedBefore): array
    {
        return $this->findRecoverable(
            static fn(Builder $query): Builder => self::whereExpiredLease($query, $claimedBefore),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function releaseExpiredLease(string $actionId, DateTimeImmutable $claimedBefore): bool
    {
        return $this->gateway->transact(
            static fn(): int => self::whereExpiredLease(ContactSubmissionActionModel::query(), $claimedBefore)
                ->where('id', $actionId)
                ->update([
                    'status' => ActionStatus::Pending,
                    'claim_token' => null,
                    'next_attempt_at' => new Expression('now()'),
                ]),
        ) > 0;
    }

    /**
     * @return list<RecoverableActionDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findOverduePending(DateTimeImmutable $dueBefore): array
    {
        return $this->findRecoverable(
            static fn(Builder $query): Builder => self::whereOverduePending($query, $dueBefore),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deferOverduePending(string $actionId, DateTimeImmutable $dueBefore): bool
    {
        return $this->gateway->transact(
            static fn(): int => self::whereOverduePending(ContactSubmissionActionModel::query(), $dueBefore)
                ->where('id', $actionId)
                ->update(['next_attempt_at' => new Expression('now()')]),
        ) > 0;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markProcessing(string $actionId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Processing,
                'processing_started_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markCompleted(string $actionId, string $externalId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Completed,
                'external_id' => $externalId,
                'completed_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markFailed(string $actionId, string $errorMessage): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Failed,
                'error_message' => $errorMessage,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function incrementAttempts(string $actionId): void
    {
        $this->gateway->transact(
            static fn(): int => ContactSubmissionActionModel::query()
                ->where('id', $actionId)
                ->increment('attempts'),
        );
    }

    /**
     * @return array<int, array{action_id: string, parent_id: string}>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findStaleProcessing(DateTimeImmutable $threshold): array
    {
        return $this->gateway->query(
            static fn(): array => ContactSubmissionActionModel::query()
                ->where('status', ActionStatus::Processing)
                ->where('processing_started_at', '<', $threshold)
                ->get(['id', 'contact_submission_id'])
                ->map(static fn(ContactSubmissionActionModel $model): array => [
                    'action_id' => $model->id,
                    'parent_id' => $model->contact_submission_id,
                ])
                ->all(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function resetToPending(string $actionId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Pending,
                'processing_started_at' => null,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getStatus(string $actionId): ?ActionStatus
    {
        return $this->gateway->query(
            static function () use ($actionId): ?ActionStatus {
                /** @var ContactSubmissionActionModel|null $model */
                $model = ContactSubmissionActionModel::query()
                    ->where('id', $actionId)
                    ->first(['status']);

                return $model?->status;
            },
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function hasCompletedAction(string $submissionId, ActionType $actionType): bool
    {
        return $this->gateway->query(
            static fn(): bool => ContactSubmissionActionModel::query()
                ->where('contact_submission_id', $submissionId)
                ->where('action_type', $actionType)
                ->where('status', ActionStatus::Completed)
                ->exists(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findActionStatus(string $submissionId, ActionType $actionType): ?ActionStatus
    {
        return $this->gateway->query(
            static function () use ($submissionId, $actionType): ?ActionStatus {
                /** @var ContactSubmissionActionModel|null $model */
                $model = ContactSubmissionActionModel::query()
                    ->where('contact_submission_id', $submissionId)
                    ->where('action_type', $actionType)
                    // ADR-0002 priority. `ELSE 5` keeps any future ActionStatus case
                    // deterministically last instead of relying on NULL-ordering.
                    ->orderByRaw("CASE status
                        WHEN 'completed' THEN 1
                        WHEN 'failed' THEN 2
                        WHEN 'processing' THEN 3
                        WHEN 'pending' THEN 4
                        ELSE 5
                    END")
                    ->first(['status']);

                return $model?->status;
            },
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function claimDuePending(string $actionId, string $token): bool
    {
        return $this->gateway->transact(
            static fn(): int => ContactSubmissionActionModel::query()
                ->where('id', $actionId)
                ->where('status', ActionStatus::Pending)
                ->where(static fn(Builder $query): Builder => $query
                    ->whereNull('next_attempt_at')
                    // intentional: queue release times are floored to whole seconds (InteractsWithTime::availableAt)
                    ->orWhereRaw("next_attempt_at <= now() + interval '5 seconds'"))
                ->update([
                    'status' => ActionStatus::Processing,
                    'attempts' => new Expression('attempts + 1'),
                    'claim_token' => $token,
                    'processing_started_at' => new Expression('now()'),
                ]),
        ) > 0;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function updateClaimed(string $actionId, string $claimToken, array $data): bool
    {
        return $this->gateway->updateWhereAll(
            ContactSubmissionActionModel::class,
            ['id' => $actionId, 'status' => ActionStatus::Processing->value, 'claim_token' => $claimToken],
            [...$data, 'claim_token' => null],
        ) > 0;
    }

    /**
     * @param Closure(Builder<ContactSubmissionActionModel>): Builder<ContactSubmissionActionModel> $predicate
     *
     * @return list<RecoverableActionDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function findRecoverable(Closure $predicate): array
    {
        return $this->gateway->query(
            /** @return list<RecoverableActionDTO> */
            static fn(): array => \array_values($predicate(ContactSubmissionActionModel::query())
                ->orderBy('created_at')
                ->get()
                ->map(static fn(ContactSubmissionActionModel $model): RecoverableActionDTO => $model->toRecoverableAction())
                ->all()),
        );
    }

    /**
     * @param Builder<ContactSubmissionActionModel> $query
     *
     * @return Builder<ContactSubmissionActionModel>
     */
    private static function whereExpiredLease(Builder $query, DateTimeImmutable $claimedBefore): Builder
    {
        return $query
            ->where('status', ActionStatus::Processing)
            ->where('processing_started_at', '<', self::timestampTz($claimedBefore));
    }

    /**
     * @param Builder<ContactSubmissionActionModel> $query
     *
     * @return Builder<ContactSubmissionActionModel>
     */
    private static function whereOverduePending(Builder $query, DateTimeImmutable $dueBefore): Builder
    {
        return $query
            ->where('status', ActionStatus::Pending)
            ->whereRaw('COALESCE(next_attempt_at, created_at) < ?', [self::timestampTz($dueBefore)]);
    }

    /**
     * Keeps the offset: Laravel formats a DateTimeInterface binding without one.
     */
    private static function timestampTz(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
