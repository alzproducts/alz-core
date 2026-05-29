<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Marketing\Models\PotentialConversionAnnotationModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Write-only repository for `marketing.potential_conversion_annotations`.
 *
 * Source-agnostic: `source_id` is either a contact submission id or a call id. Partial-update
 * semantics — only columns referenced by the command participate in the ON CONFLICT DO UPDATE
 * list, so unsent columns retain their values across upserts.
 *
 * The two stage-transition methods ({@see markDismissed}, {@see markNoQuoteExpected})
 * embed cross-table predicates that the merge-patch upsert cannot express, so they
 * inject the concrete {@see DatabaseGateway} for {@see DatabaseGateway::runSql} access.
 */
final readonly class EloquentPotentialConversionAnnotationRepository implements PotentialConversionAnnotationRepositoryInterface
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
            modelClass: PotentialConversionAnnotationModel::class,
            attributes: [
                'source_id' => $command->sourceId,
                ...$command->valuesToSet,
                ...\array_fill_keys(
                    \array_map(
                        static fn(ContactSubmissionAnnotationField $c): string => $c->value,
                        $command->columnsToClear,
                    ),
                    null,
                ),
            ],
            uniqueBy: ['source_id'],
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
    public function markDismissed(string $sourceId): void
    {
        // Single PG statement is already atomic — no explicit transact() wrapper. Matches
        // ProductPopularityRankingSnapshotRepository::writeSnapshotForToday.
        $this->databaseGateway->runSql(self::dismissSql(), [$sourceId, $sourceId, $sourceId]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markNoQuoteExpected(string $sourceId): bool
    {
        // Single-statement UPDATE — atomic in PG, no transact() needed.
        return $this->databaseGateway->runSql(self::noQuoteExpectedSql(), [$sourceId]) > 0;
    }

    /**
     * INSERT…ON CONFLICT with cross-table NOT EXISTS guards in both branches.
     *
     * Two write paths, each guarded:
     *  - INSERT branch (no existing annotation row) — guarded by the outer `WHERE NOT EXISTS` pair.
     *  - UPDATE branch (existing annotation row) — guarded by the inner `WHERE … NOT EXISTS` pair.
     * Each branch checks BOTH source tables: a `lead_received` form action OR a lead action on the
     * call matched to `source_id` blocks the dismiss. For any UUID only the relevant source's guard
     * can match (form ids never equal call ids), so checking both is safe. The call guard re-walks
     * the tracking-number + 6h-window attribution because there is no stored call→visit link.
     * `dismissed_at IS NULL` in the UPDATE branch preserves the original timestamp on repeat calls
     * (audit trail, not "last touched").
     */
    private static function dismissSql(): string
    {
        // INSERT branch matches on the `?` bound id; UPDATE branch matches on the
        // existing row's source_id. The guard pair is otherwise identical.
        $insertGuards = self::noLeadGuardPair('?');
        $updateGuards = self::noLeadGuardPair('marketing.potential_conversion_annotations.source_id');

        return <<<SQL
            INSERT INTO marketing.potential_conversion_annotations
                (source_id, dismissed_at, created_at, updated_at)
            SELECT ?, NOW(), NOW(), NOW()
            WHERE {$insertGuards}
            ON CONFLICT (source_id) DO UPDATE
            SET dismissed_at = NOW(), updated_at = NOW()
            WHERE marketing.potential_conversion_annotations.dismissed_at IS NULL
              AND {$updateGuards}
            SQL;
    }

    /**
     * Pair of cross-table NOT EXISTS guards (form lead action + attributed call lead action)
     * that block the dismiss. `$idExpr` is the SQL expression naming the row being guarded —
     * a `?` placeholder for the INSERT branch or the stored `source_id` for the UPDATE branch.
     *
     * The call guard's `INTERVAL '6 hours'` is a hard-coded mirror of the
     * `call-tracking.attribution_window_hours` config (default 6) that the dashboard view and the
     * call-tracking repositories use for the same call→visit attribution. These raw-SQL copies are
     * NOT config-driven — changing the window in config without updating them here (and in the view
     * migration) silently diverges this guard from the rest of the feature.
     */
    private static function noLeadGuardPair(string $idExpr): string
    {
        return <<<SQL
            NOT EXISTS (
                SELECT 1 FROM customer_service.contact_submission_actions
                WHERE contact_submission_id = {$idExpr}
                  AND action_type = 'lead_received'
            )
            AND NOT EXISTS (
                SELECT 1 FROM customer_service.call_tracking_actions cta
                INNER JOIN customer_service.call_tracking_visits ctv ON ctv.id = cta.call_tracking_visit_id
                INNER JOIN customer_service.call_tracking_calls ctc
                    ON ctc.tracking_number_dialled = ctv.tracking_number_shown
                   AND ctc.created_at >= ctv.created_at
                   AND ctc.created_at < ctv.created_at + INTERVAL '6 hours'
                WHERE ctc.id = {$idExpr}
            )
            SQL;
    }

    /**
     * UPDATE-only with a cross-table NOT EXISTS guard on `quote_issued`.
     *
     * Form-only: `markNoQuoteExpected` is a form endpoint (calls have no quote tracking yet) and
     * the use case rejects call rows before reaching this. Plain UPDATE (no INSERT branch) because
     * the annotation row is guaranteed to exist — the lead use case dual-writes it, and only
     * post-lead submissions reach the awaiting-quote stage where this endpoint is callable.
     */
    private static function noQuoteExpectedSql(): string
    {
        return <<<'SQL'
            UPDATE marketing.potential_conversion_annotations a
            SET is_potential_quote = false, updated_at = NOW()
            WHERE a.source_id = ?
              AND a.is_potential_quote = true
              AND NOT EXISTS (
                  SELECT 1 FROM customer_service.contact_submission_actions
                  WHERE contact_submission_id = a.source_id
                    AND action_type = 'quote_issued'
              )
            SQL;
    }
}
