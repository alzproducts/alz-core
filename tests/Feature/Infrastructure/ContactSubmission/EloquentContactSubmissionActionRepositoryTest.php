<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\ContactSubmission;

use App\Application\ContactSubmission\DTOs\RecoverableActionDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionActionRepository;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * Runs against the shared Postgres database inside a rolled-back transaction, so
 * every assertion is scoped to rows this test seeds. Postgres `now()` is fixed for
 * the whole transaction, so seeded and expected timestamps are relative to it.
 */
#[CoversClass(EloquentContactSubmissionActionRepository::class)]
final class EloquentContactSubmissionActionRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'customer_service.contact_submission_actions';

    private ContactSubmissionActionRepositoryInterface $repository;

    private CarbonImmutable $dbNow;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(ContactSubmissionActionRepositoryInterface::class);
        // The table's timestamptz(0) columns round to whole seconds.
        $this->dbNow = CarbonImmutable::parse((string) DB::selectOne('SELECT now()::timestamptz(0) AS now')->now);
    }

    #[Test]
    public function claim_moves_a_due_pending_row_to_processing_under_a_new_token(): void
    {
        $id = $this->insertAction(['attempts' => 2, 'external_id' => '12345']);

        $claim = $this->repository->claim($id);

        self::assertNotNull($claim);
        self::assertSame(3, $claim->attempts);
        self::assertSame('12345', $claim->externalId);
        self::assertSame($this->format($this->dbNow), $this->format(CarbonImmutable::instance($claim->createdAt)));

        $row = $this->row($id);
        self::assertSame('processing', $row->status);
        self::assertSame(3, $row->attempts);
        self::assertSame($claim->token, $row->claim_token);
        $this->assertTimestamp($this->dbNow, $row->processing_started_at);
    }

    #[Test]
    public function a_second_claim_of_the_same_row_returns_null(): void
    {
        $id = $this->insertAction();

        $first = $this->repository->claim($id);
        $second = $this->repository->claim($id);

        self::assertNotNull($first);
        self::assertNull($second);
        $row = $this->row($id);
        self::assertSame(1, $row->attempts);
        self::assertSame($first->token, $row->claim_token);
    }

    #[Test]
    public function claim_succeeds_two_seconds_before_next_attempt_at(): void
    {
        $id = $this->insertAction(['next_attempt_at' => DB::raw("now() + interval '2 seconds'")]);

        $claim = $this->repository->claim($id);

        self::assertNotNull($claim);
        self::assertSame('processing', $this->row($id)->status);
    }

    #[Test]
    public function claim_succeeds_once_next_attempt_at_has_passed(): void
    {
        $id = $this->insertAction(['next_attempt_at' => DB::raw("now() - interval '1 minute'")]);

        self::assertNotNull($this->repository->claim($id));
    }

    #[Test]
    public function claim_returns_null_for_a_row_not_yet_due(): void
    {
        $id = $this->insertAction(['next_attempt_at' => DB::raw("now() + interval '10 seconds'")]);

        self::assertNull($this->repository->claim($id));

        $row = $this->row($id);
        self::assertSame('pending', $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->claim_token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPendingStatuses(): iterable
    {
        yield 'processing' => ['processing'];
        yield 'completed' => ['completed'];
        yield 'failed' => ['failed'];
    }

    #[Test]
    #[DataProvider('nonPendingStatuses')]
    public function claim_returns_null_for_a_row_that_is_not_pending(string $status): void
    {
        $id = $this->insertAction(['status' => $status, 'attempts' => 1]);

        self::assertNull($this->repository->claim($id));

        $row = $this->row($id);
        self::assertSame($status, $row->status);
        self::assertSame(1, $row->attempts);
    }

    #[Test]
    public function complete_claim_completes_the_held_row_and_clears_the_token(): void
    {
        $id = $this->insertAction();
        $claim = $this->repository->claim($id);
        self::assertNotNull($claim);

        self::assertTrue($this->repository->completeClaim($id, $claim->token, '98765'));

        $row = $this->row($id);
        self::assertSame('completed', $row->status);
        self::assertSame('98765', $row->external_id);
        self::assertNull($row->claim_token);
        $this->assertTimestamp($this->dbNow, $row->completed_at);
    }

    #[Test]
    public function fail_claim_fails_the_held_row_and_clears_the_token(): void
    {
        $id = $this->insertAction();
        $claim = $this->repository->claim($id);
        self::assertNotNull($claim);

        self::assertTrue($this->repository->failClaim($id, $claim->token, 'Permanent API failure'));

        $row = $this->row($id);
        self::assertSame('failed', $row->status);
        self::assertSame('Permanent API failure', $row->error_message);
        self::assertNull($row->claim_token);
    }

    #[Test]
    public function return_to_pending_releases_the_held_row_with_a_delay(): void
    {
        $id = $this->insertAction();
        $claim = $this->repository->claim($id);
        self::assertNotNull($claim);

        $earliest = CarbonImmutable::now()->addSeconds(300)->subSecond();

        self::assertTrue($this->repository->returnToPending($id, $claim->token, 300));

        $latest = CarbonImmutable::now()->addSeconds(300)->addSecond();
        $row = $this->row($id);
        self::assertSame('pending', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNull($row->claim_token);
        self::assertIsString($row->next_attempt_at);
        self::assertTrue(CarbonImmutable::parse($row->next_attempt_at)->between($earliest, $latest));
    }

    #[Test]
    public function fail_claim_leaves_a_completed_row_and_its_external_id_unchanged(): void
    {
        $token = Str::uuid()->toString();
        $id = $this->insertAction(['status' => 'completed', 'external_id' => '55555', 'claim_token' => $token]);

        self::assertFalse($this->repository->failClaim($id, $token, 'late failure'));

        $row = $this->row($id);
        self::assertSame('completed', $row->status);
        self::assertSame('55555', $row->external_id);
        self::assertNull($row->error_message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProcessingStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'completed' => ['completed'];
        yield 'failed' => ['failed'];
    }

    #[Test]
    #[DataProvider('nonProcessingStatuses')]
    public function complete_claim_and_return_to_pending_leave_a_row_that_is_not_processing_unchanged(string $status): void
    {
        $token = Str::uuid()->toString();
        $id = $this->insertAction(['status' => $status, 'external_id' => '44444', 'claim_token' => $token]);

        self::assertFalse($this->repository->completeClaim($id, $token, '99999'));
        self::assertFalse($this->repository->returnToPending($id, $token, 60));

        $row = $this->row($id);
        self::assertSame($status, $row->status);
        self::assertSame('44444', $row->external_id);
        self::assertSame($token, $row->claim_token);
        self::assertNull($row->next_attempt_at);
    }

    #[Test]
    public function transitions_with_a_token_that_does_not_match_leave_the_row_unchanged(): void
    {
        $id = $this->insertAction();
        $claim = $this->repository->claim($id);
        self::assertNotNull($claim);
        $foreignToken = Str::uuid()->toString();

        self::assertFalse($this->repository->completeClaim($id, $foreignToken, '99999'));
        self::assertFalse($this->repository->failClaim($id, $foreignToken, 'lost claim'));
        self::assertFalse($this->repository->returnToPending($id, $foreignToken, 60));

        $row = $this->row($id);
        self::assertSame('processing', $row->status);
        self::assertSame($claim->token, $row->claim_token);
        self::assertNull($row->external_id);
        self::assertNull($row->error_message);
        self::assertNull($row->next_attempt_at);
    }

    #[Test]
    public function fail_unclaimed_fails_a_pending_row(): void
    {
        $id = $this->insertAction();

        self::assertTrue($this->repository->failUnclaimed($id, 'Missing quote details'));

        $row = $this->row($id);
        self::assertSame('failed', $row->status);
        self::assertSame('Missing quote details', $row->error_message);
    }

    #[Test]
    public function fail_unclaimed_leaves_a_claimed_row_unchanged(): void
    {
        $id = $this->insertAction();
        $claim = $this->repository->claim($id);
        self::assertNotNull($claim);

        self::assertFalse($this->repository->failUnclaimed($id, 'Missing quote details'));

        $row = $this->row($id);
        self::assertSame('processing', $row->status);
        self::assertSame($claim->token, $row->claim_token);
        self::assertNull($row->error_message);
    }

    #[Test]
    public function expired_leases_are_found_and_released_once(): void
    {
        $claimedBefore = $this->dbNow->subSeconds(360);
        $expired = $this->insertAction([
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => Str::uuid()->toString(),
            'processing_started_at' => $this->dbNow->subSeconds(400)->toIso8601String(),
        ]);
        $live = $this->insertAction([
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => Str::uuid()->toString(),
            'processing_started_at' => $this->dbNow->subSeconds(100)->toIso8601String(),
        ]);
        $pending = $this->insertAction(['processing_started_at' => $this->dbNow->subSeconds(400)->toIso8601String()]);

        $found = $this->idsOf($this->repository->findExpiredLeases($claimedBefore), [$expired, $live, $pending]);

        self::assertSame([$expired], $found);
        self::assertTrue($this->repository->releaseExpiredLease($expired, $claimedBefore));
        self::assertFalse($this->repository->releaseExpiredLease($expired, $claimedBefore));
        self::assertFalse($this->repository->releaseExpiredLease($live, $claimedBefore));

        $row = $this->row($expired);
        self::assertSame('pending', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNull($row->claim_token);
        $this->assertTimestamp($this->dbNow, $row->next_attempt_at);
        self::assertSame('processing', $this->row($live)->status);
    }

    #[Test]
    public function a_released_lease_can_be_claimed_again(): void
    {
        $claimedBefore = $this->dbNow->subSeconds(360);
        $id = $this->insertAction([
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => Str::uuid()->toString(),
            'processing_started_at' => $this->dbNow->subSeconds(400)->toIso8601String(),
        ]);

        self::assertTrue($this->repository->releaseExpiredLease($id, $claimedBefore));
        $claim = $this->repository->claim($id);

        self::assertNotNull($claim);
        self::assertSame(2, $claim->attempts);
    }

    #[Test]
    public function overdue_pending_rows_are_found_and_deferred_once(): void
    {
        $dueBefore = $this->dbNow->subSeconds(600);
        $old = $this->dbNow->subSeconds(700)->toIso8601String();
        $neverAttempted = $this->insertAction(['created_at' => $old]);
        $retryOverdue = $this->insertAction(['created_at' => $old, 'next_attempt_at' => $old]);
        $retryScheduled = $this->insertAction(['created_at' => $old, 'next_attempt_at' => DB::raw("now() + interval '1 minute'")]);
        $recent = $this->insertAction(['created_at' => $this->dbNow->subSeconds(100)->toIso8601String()]);
        $processing = $this->insertAction(['created_at' => $old, 'status' => 'processing']);

        $found = $this->idsOf(
            $this->repository->findOverduePending($dueBefore),
            [$neverAttempted, $retryOverdue, $retryScheduled, $recent, $processing],
        );

        self::assertEqualsCanonicalizing([$neverAttempted, $retryOverdue], $found);
        self::assertTrue($this->repository->deferOverduePending($neverAttempted, $dueBefore));
        self::assertFalse($this->repository->deferOverduePending($neverAttempted, $dueBefore));
        self::assertFalse($this->repository->deferOverduePending($retryScheduled, $dueBefore));
        self::assertFalse($this->repository->deferOverduePending($processing, $dueBefore));

        $row = $this->row($neverAttempted);
        self::assertSame('pending', $row->status);
        $this->assertTimestamp($this->dbNow, $row->next_attempt_at);
    }

    #[Test]
    public function linked_conversation_ids_returns_ids_held_by_other_helpscout_actions(): void
    {
        $ownId = 'conv-' . Str::uuid()->toString();
        $otherId = 'conv-' . Str::uuid()->toString();
        $leadOnlyId = 'conv-' . Str::uuid()->toString();
        $unlinkedId = 'conv-' . Str::uuid()->toString();
        $current = $this->insertAction(['external_id' => $ownId, 'status' => 'completed']);
        $this->insertAction(['external_id' => $otherId, 'status' => 'completed']);
        $this->insertAction(['external_id' => $leadOnlyId, 'action_type' => 'lead_received', 'ad_platform' => 'google']);

        $linked = $this->repository->linkedConversationIds([$ownId, $otherId, $leadOnlyId, $unlinkedId], $current);

        self::assertSame([$otherId], $linked);
        self::assertSame([], $this->repository->linkedConversationIds([], $current));
    }

    #[Test]
    public function create_quote_action_stores_the_quote_details_for_recovery(): void
    {
        $submissionId = $this->insertSubmission();
        $convertedAt = new DateTimeImmutable('2026-10-28T10:15:30+01:00');

        $id = $this->repository->createQuoteAction($submissionId, AdPlatform::Bing, Money::exclusiveFromString('123.456789'), $convertedAt);

        $row = $this->row($id);
        self::assertSame('quote_issued', $row->action_type);
        self::assertSame('bing', $row->ad_platform);
        self::assertSame('pending', $row->status);
        self::assertSame('123.456789', $row->quote_value_net);
        $this->assertTimestamp(CarbonImmutable::instance($convertedAt), $row->quote_converted_at);

        DB::table(self::TABLE)->where('id', $id)->update(['created_at' => $this->dbNow->subSeconds(700)->toIso8601String()]);
        $recoverable = $this->findRecoverable($this->repository->findOverduePending($this->dbNow->subSeconds(600)), $id);

        self::assertSame($submissionId, $recoverable->submissionId);
        self::assertSame(ActionType::QuoteIssued, $recoverable->actionType);
        self::assertSame(AdPlatform::Bing, $recoverable->adPlatform);
        self::assertNotNull($recoverable->quoteValue);
        self::assertSame(123.456789, $recoverable->quoteValue->toNet(precision: null));
        self::assertNotNull($recoverable->quoteConvertedAt);
        self::assertSame($convertedAt->getTimestamp(), $recoverable->quoteConvertedAt->getTimestamp());
    }

    #[Test]
    public function recoverable_action_without_quote_details_maps_them_to_null(): void
    {
        $id = $this->insertAction(['created_at' => $this->dbNow->subSeconds(700)->toIso8601String()]);

        $recoverable = $this->findRecoverable($this->repository->findOverduePending($this->dbNow->subSeconds(600)), $id);

        self::assertSame(ActionType::HelpScout, $recoverable->actionType);
        self::assertNull($recoverable->adPlatform);
        self::assertNull($recoverable->quoteValue);
        self::assertNull($recoverable->quoteConvertedAt);
    }

    #[Test]
    public function find_action_type_returns_the_row_type_or_null(): void
    {
        $id = $this->insertAction(['action_type' => 'lead_received', 'ad_platform' => 'google']);

        self::assertSame(ActionType::LeadReceived, $this->repository->findActionType($id));
        self::assertNull($this->repository->findActionType(Str::uuid()->toString()));
    }

    private function insertSubmission(): string
    {
        $id = Str::uuid()->toString();

        DB::table('public_ingest.contact_submissions')->insert([
            'id' => $id,
            'name' => 'Repository Test',
            'email' => 'repository-test@example.com',
            'reason' => 'general',
            'message' => 'Seeded by EloquentContactSubmissionActionRepositoryTest',
            'client_timestamp' => $this->dbNow->toIso8601String(),
            'ip_address' => '127.0.0.1',
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertAction(array $overrides = []): string
    {
        $id = Str::uuid()->toString();

        DB::table(self::TABLE)->insert([
            'id' => $id,
            'contact_submission_id' => $this->insertSubmission(),
            'action_type' => 'helpscout',
            'status' => 'pending',
            'attempts' => 0,
            ...$overrides,
        ]);

        return $id;
    }

    private function row(string $id): stdClass
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    /**
     * @param list<RecoverableActionDTO> $actions
     * @param list<string> $seededIds
     *
     * @return list<string>
     */
    private function idsOf(array $actions, array $seededIds): array
    {
        return \array_values(\array_filter(
            \array_map(static fn(RecoverableActionDTO $action): string => $action->actionId, $actions),
            static fn(string $id): bool => \in_array($id, $seededIds, true),
        ));
    }

    /**
     * @param list<RecoverableActionDTO> $actions
     */
    private function findRecoverable(array $actions, string $id): RecoverableActionDTO
    {
        $match = \array_find($actions, static fn(RecoverableActionDTO $action): bool => $action->actionId === $id);
        self::assertInstanceOf(RecoverableActionDTO::class, $match);

        return $match;
    }

    private function assertTimestamp(CarbonImmutable $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame($this->format($expected), $this->format(CarbonImmutable::parse($actual)));
    }

    private function format(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.u');
    }
}
