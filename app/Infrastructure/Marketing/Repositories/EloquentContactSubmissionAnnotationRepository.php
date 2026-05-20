<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Marketing\Models\ContactSubmissionAnnotationModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Write-only repository for `marketing.contact_submission_annotations`.
 *
 * Partial-update semantics: only columns referenced by the command participate in the
 * ON CONFLICT DO UPDATE list, so unsent columns retain their values across upserts.
 *
 * The two stage-transition methods ({@see markDismissed}, {@see markNoQuoteExpected})
 * embed cross-table predicates that the merge-patch upsert cannot express, so they
 * inject the concrete {@see DatabaseGateway} for raw `affectingStatement` access.
 */
final readonly class EloquentContactSubmissionAnnotationRepository implements ContactSubmissionAnnotationRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
        private DatabaseGateway $databaseGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function upsert(UpsertAnnotationCommand $command): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: ContactSubmissionAnnotationModel::class,
            attributes: [
                'contact_submission_id' => $command->contactSubmissionId,
                ...$command->valuesToSet,
                ...\array_fill_keys(
                    \array_map(
                        static fn(ContactSubmissionAnnotationField $c): string => $c->value,
                        $command->columnsToClear,
                    ),
                    null,
                ),
            ],
            uniqueBy: ['contact_submission_id'],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markDismissed(string $submissionId): void
    {
        // Single PG statement is already atomic — no explicit transact() wrapper. Matches
        // ProductPopularityRankingSnapshotRepository::writeSnapshotForToday.
        $this->databaseGateway->query(
            fn(): int => $this->databaseGateway->connection()->affectingStatement(
                self::dismissSql(),
                [$submissionId, $submissionId],
            ),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markNoQuoteExpected(string $submissionId): void
    {
        // Single-statement UPDATE — atomic in PG, no transact() needed.
        $this->databaseGateway->query(
            fn(): int => $this->databaseGateway->connection()->affectingStatement(
                self::noQuoteExpectedSql(),
                [$submissionId],
            ),
        );
    }

    /**
     * INSERT…ON CONFLICT with cross-table NOT EXISTS guards in both branches.
     *
     * Two guards because two write paths exist:
     *  - INSERT branch (no existing annotation row) — guarded by the outer `WHERE NOT EXISTS`.
     *  - UPDATE branch (existing annotation row) — guarded by the inner `WHERE … NOT EXISTS`.
     * Both reject when a `lead_received` action appears between the use-case pre-check and the
     * SQL execution. `dismissed_at IS NULL` in the UPDATE branch preserves the original
     * dismiss timestamp on repeat calls (audit trail, not "last touched").
     */
    private static function dismissSql(): string
    {
        return <<<'SQL'
            INSERT INTO marketing.contact_submission_annotations
                (contact_submission_id, dismissed_at, created_at, updated_at)
            SELECT ?, NOW(), NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM customer_service.contact_submission_actions
                WHERE contact_submission_id = ?
                  AND action_type = 'lead_received'
            )
            ON CONFLICT (contact_submission_id) DO UPDATE
            SET dismissed_at = NOW(), updated_at = NOW()
            WHERE marketing.contact_submission_annotations.dismissed_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM customer_service.contact_submission_actions
                  WHERE contact_submission_id = marketing.contact_submission_annotations.contact_submission_id
                    AND action_type = 'lead_received'
              )
            SQL;
    }

    /**
     * UPDATE-only with a cross-table NOT EXISTS guard on `quote_issued`.
     *
     * Plain UPDATE (no INSERT branch) because the annotation row is guaranteed to exist —
     * Stage 2's lead use case dual-writes it, and only post-lead submissions can reach the
     * awaiting-quote stage where this endpoint is callable.
     */
    private static function noQuoteExpectedSql(): string
    {
        return <<<'SQL'
            UPDATE marketing.contact_submission_annotations a
            SET is_potential_quote = false, updated_at = NOW()
            WHERE a.contact_submission_id = ?
              AND a.is_potential_quote = true
              AND NOT EXISTS (
                  SELECT 1 FROM customer_service.contact_submission_actions
                  WHERE contact_submission_id = a.contact_submission_id
                    AND action_type = 'quote_issued'
              )
            SQL;
    }
}
