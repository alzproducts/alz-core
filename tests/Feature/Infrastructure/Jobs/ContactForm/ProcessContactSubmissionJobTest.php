<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\ContactForm;

use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Jobs\ContactForm\ProcessContactSubmissionJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;
use Throwable;

/**
 * Runs against the shared Postgres database inside a rolled-back transaction, asserting
 * only on rows this test seeds.
 */
#[CoversClass(ProcessContactSubmissionJob::class)]
final class ProcessContactSubmissionJobTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'customer_service.contact_submission_actions';

    /** @var list<Throwable> */
    private array $jobFailures = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ContactFormProcessingFailedEvent::class]);
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->jobFailures[] = $event->exception;
        });
    }

    #[Test]
    public function a_queue_level_failure_leaves_a_pending_action_untouched_and_fires_no_failure_event(): void
    {
        [$submissionId, $actionId] = $this->insertPendingAction();
        $before = $this->row($actionId);
        $exception = new InvalidApiRequestException('HelpScout', 'bad request');
        $this->bindUseCaseThrowing($exception);

        ProcessContactSubmissionJob::dispatch($submissionId, $actionId)->onConnection('sync');

        self::assertSame([$exception], $this->jobFailures);
        self::assertEquals($before, $this->row($actionId));
        Event::assertNotDispatched(ContactFormProcessingFailedEvent::class);
    }

    #[Test]
    public function an_action_type_mismatch_fails_the_job_at_once(): void
    {
        [$submissionId, $actionId] = $this->insertPendingAction();
        $exception = new ActionTypeMismatchException($actionId, ActionType::HelpScout, ActionType::LeadReceived);
        $this->bindUseCaseThrowing($exception);

        ProcessContactSubmissionJob::dispatch($submissionId, $actionId)->onConnection('sync');

        self::assertSame([$exception], $this->jobFailures);
    }

    #[Test]
    public function the_job_handles_api_exceptions_outside_the_circuit_breaker(): void
    {
        $middleware = new ProcessContactSubmissionJob('submission-id', 'action-id')->middleware();
        $classes = \array_map(static fn(object $item): string => $item::class, $middleware);

        $handlerIndex = \array_search(HandleApiExceptions::class, $classes, true);
        $breakerIndex = \array_search(ServiceCircuitBreaker::class, $classes, true);

        self::assertIsInt($handlerIndex);
        self::assertIsInt($breakerIndex);
        self::assertLessThan($breakerIndex, $handlerIndex);
    }

    private function bindUseCaseThrowing(Throwable $exception): void
    {
        $useCase = Mockery::mock(ProcessContactSubmissionUseCase::class);
        $useCase->expects('execute')->andThrow($exception);
        $this->app->instance(ProcessContactSubmissionUseCase::class, $useCase);
    }

    /**
     * @return array{string, string}
     */
    private function insertPendingAction(): array
    {
        $submissionId = Str::uuid()->toString();
        $actionId = Str::uuid()->toString();

        DB::table('public_ingest.contact_submissions')->insert([
            'id' => $submissionId,
            'name' => 'Job Test',
            'email' => 'job-test@example.com',
            'reason' => 'general',
            'message' => 'Seeded by ProcessContactSubmissionJobTest',
            'client_timestamp' => \now()->toIso8601String(),
            'ip_address' => '127.0.0.1',
        ]);

        DB::table(self::TABLE)->insert([
            'id' => $actionId,
            'contact_submission_id' => $submissionId,
            'action_type' => 'helpscout',
            'status' => 'pending',
            'attempts' => 0,
        ]);

        return [$submissionId, $actionId];
    }

    private function row(string $id): stdClass
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('pending', $row->status);

        return $row;
    }
}
