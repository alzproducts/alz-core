<?php

declare(strict_types=1);

namespace Tests\Feature\Application\ContactSubmission;

use App\Application\ContactSubmission\UseCases\CleanupStaleContactActionsUseCase;
use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * Sweeps the shared Postgres database inside a rolled-back transaction, so every
 * assertion is scoped to rows this test seeds. Postgres `now()` is fixed for the
 * whole transaction, so seeded timestamps are relative to it.
 */
#[CoversClass(CleanupStaleContactActionsUseCase::class)]
final class CleanupStaleContactActionsUseCaseTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'customer_service.contact_submission_actions';

    private CarbonImmutable $dbNow;

    /** @var ContactFormDispatcherInterface&object{dispatches: list<array{string, string}>} */
    private ContactFormDispatcherInterface $contactFormDispatcher;

    /** @var ConversionDispatcherInterface&object{leads: list<LeadConversionCommand>, quotes: list<QuoteConversionCommand>} */
    private ConversionDispatcherInterface $conversionDispatcher;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The table's timestamptz(0) columns round to whole seconds.
        $this->dbNow = CarbonImmutable::parse((string) DB::selectOne('SELECT now()::timestamptz(0) AS now')->now);

        $this->contactFormDispatcher = new class implements ContactFormDispatcherInterface {
            /** @var list<array{string, string}> */
            public array $dispatches = [];

            #[Override]
            public function dispatchContactSubmissionProcessing(string $submissionId, string $actionId): void
            {
                $this->dispatches[] = [$submissionId, $actionId];
            }
        };

        $this->conversionDispatcher = new class implements ConversionDispatcherInterface {
            /** @var list<LeadConversionCommand> */
            public array $leads = [];

            /** @var list<QuoteConversionCommand> */
            public array $quotes = [];

            #[Override]
            public function dispatchLeadConversion(LeadConversionCommand $command): void
            {
                $this->leads[] = $command;
            }

            #[Override]
            public function dispatchQuoteConversion(QuoteConversionCommand $command): void
            {
                $this->quotes[] = $command;
            }
        };

        $this->app->instance(ContactFormDispatcherInterface::class, $this->contactFormDispatcher);
        $this->app->instance(ConversionDispatcherInterface::class, $this->conversionDispatcher);
    }

    #[Test]
    public function an_expired_lead_lease_dispatches_the_lead_conversion_for_its_platform_and_no_helpscout_job(): void
    {
        $submissionId = $this->insertSubmission();
        $id = $this->insertAction($this->expiredLease([
            'contact_submission_id' => $submissionId,
            'action_type' => 'lead_received',
            'ad_platform' => 'bing',
        ]));

        $this->sweep();

        $leads = $this->leadDispatchesFor($id);
        self::assertCount(1, $leads);
        self::assertSame($submissionId, $leads[0]->submissionId->value);
        self::assertSame(AdPlatform::Bing, $leads[0]->platform);
        self::assertSame([], $this->contactFormDispatchesFor($id));
        self::assertSame([], $this->quoteDispatchesFor($id));
        self::assertSame('pending', $this->row($id)->status);
    }

    #[Test]
    public function an_overdue_quote_dispatches_a_quote_conversion_carrying_the_stored_details(): void
    {
        $submissionId = $this->insertSubmission();
        $convertedAt = CarbonImmutable::parse('2026-10-20T14:30:00Z');
        $id = $this->insertAction([
            'contact_submission_id' => $submissionId,
            'action_type' => 'quote_issued',
            'ad_platform' => 'google',
            'created_at' => $this->secondsAgo(660),
            'quote_value_net' => '1234.567800',
            'quote_converted_at' => $convertedAt->toIso8601String(),
        ]);

        $this->sweep();

        $quotes = $this->quoteDispatchesFor($id);
        self::assertCount(1, $quotes);
        self::assertSame($submissionId, $quotes[0]->submissionId->value);
        self::assertSame(AdPlatform::Google, $quotes[0]->platform);
        self::assertSame(1234.5678, $quotes[0]->value->toNet(precision: null));
        self::assertSame($convertedAt->getTimestamp(), $quotes[0]->convertedAt->getTimestamp());
        self::assertSame([], $this->contactFormDispatchesFor($id));
        self::assertSame([], $this->leadDispatchesFor($id));
    }

    #[Test]
    public function an_overdue_helpscout_action_dispatches_contact_submission_processing_and_is_deferred(): void
    {
        $submissionId = $this->insertSubmission();
        $id = $this->insertAction([
            'contact_submission_id' => $submissionId,
            'created_at' => $this->secondsAgo(660),
        ]);

        $this->sweep();

        self::assertSame([[$submissionId, $id]], $this->contactFormDispatchesFor($id));
        $row = $this->row($id);
        self::assertSame('pending', $row->status);
        $this->assertTimestamp($this->dbNow, $row->next_attempt_at);
    }

    #[Test]
    public function a_recent_helpscout_action_is_not_dispatched(): void
    {
        $id = $this->insertAction(['created_at' => $this->secondsAgo(120)]);

        $this->sweep();

        self::assertSame([], $this->contactFormDispatchesFor($id));
        self::assertNull($this->row($id)->next_attempt_at);
    }

    #[Test]
    public function a_pending_action_due_in_the_future_is_not_dispatched(): void
    {
        $id = $this->insertAction([
            'created_at' => $this->secondsAgo(3600),
            'attempts' => 1,
            'next_attempt_at' => DB::raw("now() + interval '5 minutes'"),
        ]);

        $this->sweep();

        self::assertSame([], $this->contactFormDispatchesFor($id));
        self::assertSame('pending', $this->row($id)->status);
    }

    #[Test]
    public function an_expired_lease_is_reset_to_pending_and_a_following_process_run_claims_it(): void
    {
        $id = $this->insertAction($this->expiredLease([]));

        $this->sweep();

        $row = $this->row($id);
        self::assertSame('pending', $row->status);
        self::assertNull($row->claim_token);
        self::assertCount(1, $this->contactFormDispatchesFor($id));

        $claim = $this->app->make(ContactSubmissionActionRepositoryInterface::class)->claim($id);

        self::assertNotNull($claim);
        self::assertSame(2, $claim->attempts);
        self::assertSame('processing', $this->row($id)->status);
    }

    #[Test]
    public function a_processing_action_inside_its_lease_is_left_unchanged_and_not_dispatched(): void
    {
        $token = Str::uuid()->toString();
        $startedAt = $this->secondsAgo(100);
        $id = $this->insertAction([
            'action_type' => 'lead_received',
            'ad_platform' => 'google',
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => $token,
            'created_at' => $this->secondsAgo(3600),
            'processing_started_at' => $startedAt,
        ]);

        $this->sweep();

        $row = $this->row($id);
        self::assertSame('processing', $row->status);
        self::assertSame($token, $row->claim_token);
        self::assertSame(1, $row->attempts);
        $this->assertTimestamp(CarbonImmutable::parse($startedAt), $row->processing_started_at);
        self::assertNull($row->next_attempt_at);
        self::assertSame([], $this->leadDispatchesFor($id));
        self::assertSame([], $this->contactFormDispatchesFor($id));
    }

    #[Test]
    public function a_quote_without_stored_details_ends_failed_and_is_not_dispatched(): void
    {
        $id = $this->insertAction([
            'action_type' => 'quote_issued',
            'ad_platform' => 'google',
            'created_at' => $this->secondsAgo(660),
        ]);

        $this->sweep();

        $row = $this->row($id);
        self::assertSame('failed', $row->status);
        self::assertSame('Quote action has no stored ad platform, value or converted-at', $row->error_message);
        self::assertSame([], $this->quoteDispatchesFor($id));
        self::assertSame([], $this->contactFormDispatchesFor($id));
    }

    #[Test]
    public function an_expired_quote_lease_without_stored_details_ends_failed(): void
    {
        $id = $this->insertAction($this->expiredLease([
            'action_type' => 'quote_issued',
            'ad_platform' => 'google',
        ]));

        $this->sweep();

        self::assertSame('failed', $this->row($id)->status);
        self::assertSame([], $this->quoteDispatchesFor($id));
    }

    private function sweep(): void
    {
        $this->app->make(CleanupStaleContactActionsUseCase::class)->execute();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function expiredLease(array $overrides): array
    {
        return [
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => Str::uuid()->toString(),
            'created_at' => $this->secondsAgo(3600),
            'processing_started_at' => $this->secondsAgo(400),
            ...$overrides,
        ];
    }

    private function secondsAgo(int $seconds): string
    {
        return $this->dbNow->subSeconds($seconds)->toIso8601String();
    }

    /**
     * @return list<array{string, string}>
     */
    private function contactFormDispatchesFor(string $actionId): array
    {
        return \array_values(\array_filter(
            $this->contactFormDispatcher->dispatches,
            static fn(array $dispatch): bool => $dispatch[1] === $actionId,
        ));
    }

    /**
     * @return list<LeadConversionCommand>
     */
    private function leadDispatchesFor(string $actionId): array
    {
        return \array_values(\array_filter(
            $this->conversionDispatcher->leads,
            static fn(LeadConversionCommand $command): bool => $command->actionId->value === $actionId,
        ));
    }

    /**
     * @return list<QuoteConversionCommand>
     */
    private function quoteDispatchesFor(string $actionId): array
    {
        return \array_values(\array_filter(
            $this->conversionDispatcher->quotes,
            static fn(QuoteConversionCommand $command): bool => $command->actionId->value === $actionId,
        ));
    }

    private function insertSubmission(): string
    {
        $id = Str::uuid()->toString();

        DB::table('public_ingest.contact_submissions')->insert([
            'id' => $id,
            'name' => 'Sweeper Test',
            'email' => 'sweeper-test@example.com',
            'reason' => 'general',
            'message' => 'Seeded by CleanupStaleContactActionsUseCaseTest',
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

    private function assertTimestamp(CarbonImmutable $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame(
            $expected->utc()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($actual)->utc()->format('Y-m-d H:i:s'),
        );
    }
}
