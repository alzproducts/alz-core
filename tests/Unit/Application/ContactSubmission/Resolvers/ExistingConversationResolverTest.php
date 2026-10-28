<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\Resolvers;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\ContactSubmission\Resolvers\ExistingConversationResolver;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\HelpScout\Queries\Conversation\Enums\SortField;
use App\Application\HelpScout\Queries\Conversation\Enums\SortOrder;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ExistingConversationResolver::class)]
final class ExistingConversationResolverTest extends TestCase
{
    private const string SUBMISSION_ID = 'submission-uuid-123';

    private const string ACTION_ID = 'action-uuid-456';

    private const string EMAIL = 'jane@example.com';

    private const int MAILBOX_ID = 321;

    private const string ACTION_CREATED_AT = '2024-01-15T10:00:00Z';

    private ContactConversationLookupInterface&MockInterface $lookup;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private LoggerInterface&MockInterface $logger;

    private ExistingConversationResolver $resolver;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = Mockery::mock(ContactConversationLookupInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->resolver = new ExistingConversationResolver(
            $this->lookup,
            $this->actionRepository,
            $this->logger,
            self::MAILBOX_ID,
        );
    }

    #[Test]
    public function a_first_attempt_returns_null_without_a_search(): void
    {
        $this->lookup->shouldNotReceive('findMatches');

        self::assertNull($this->resolve(attempts: 1));
    }

    #[Test]
    public function a_single_unlinked_match_returns_its_id(): void
    {
        $this->lookup->expects('findMatches')
            ->with(Mockery::on(static fn(ConversationQueryParams $p): bool => $p->status === 'all'
                && $p->mailboxId === self::MAILBOX_ID
                && $p->sortField === SortField::CreatedAt
                && $p->sortOrder === SortOrder::Desc
                && $p->query === '(email:"jane@example.com" AND createdAt:[2024-01-15T09:59:00Z TO *])'))
            ->andReturn([self::conversation(777)]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777'], self::ACTION_ID)->andReturn([]);

        self::assertSame('777', $this->resolve());
    }

    #[Test]
    public function the_email_matches_case_insensitively(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777, email: 'Jane@EXAMPLE.com')]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777'], self::ACTION_ID)->andReturn([]);

        self::assertSame('777', $this->resolve());
    }

    #[Test]
    public function a_match_with_another_email_is_ignored(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777, email: 'other@example.com')]);
        $this->actionRepository->shouldNotReceive('linkedConversationIds');

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_match_without_a_customer_email_is_ignored(): void
    {
        $this->lookup->expects('findMatches')->andReturn([
            self::conversation(777, email: null),
            self::conversation(778, withCustomer: false),
        ]);
        $this->actionRepository->shouldNotReceive('linkedConversationIds');

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_match_with_another_subject_is_ignored(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777, subject: '[Other] Contact Us Form (2)')]);
        $this->actionRepository->shouldNotReceive('linkedConversationIds');

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_match_created_before_the_clock_skew_window_is_ignored(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777, createdAt: '2024-01-15T09:58:59Z')]);
        $this->actionRepository->shouldNotReceive('linkedConversationIds');

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_match_created_at_the_clock_skew_bound_is_kept(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777, createdAt: '2024-01-15T09:59:00Z')]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777'], self::ACTION_ID)->andReturn([]);

        self::assertSame('777', $this->resolve());
    }

    #[Test]
    public function a_match_linked_to_another_action_is_dropped(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777)]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777'], self::ACTION_ID)->andReturn(['777']);

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_linked_match_beside_one_unlinked_match_returns_the_unlinked_id(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777), self::conversation(778)]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777', '778'], self::ACTION_ID)->andReturn(['777']);

        self::assertSame('778', $this->resolve());
    }

    #[Test]
    public function two_unlinked_matches_return_null(): void
    {
        $this->lookup->expects('findMatches')->andReturn([self::conversation(777), self::conversation(778)]);
        $this->actionRepository->expects('linkedConversationIds')->with(['777', '778'], self::ACTION_ID)->andReturn([]);

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_permanent_search_failure_logs_a_warning_and_returns_null(): void
    {
        $this->lookup->expects('findMatches')->andThrow(new AuthenticationExpiredException('HelpScout', 'Invalid credentials'));
        $this->actionRepository->shouldNotReceive('linkedConversationIds');
        $this->logger->expects('warning')->with('Existing HelpScout conversation search failed', [
            'submission_id' => self::SUBMISSION_ID,
            'action_id' => self::ACTION_ID,
            'exception_class' => AuthenticationExpiredException::class,
        ]);

        self::assertNull($this->resolve());
    }

    #[Test]
    public function a_transient_search_failure_propagates(): void
    {
        $exception = new ExternalServiceUnavailableException('HelpScout');
        $this->lookup->expects('findMatches')->andThrow($exception);

        $this->expectExceptionObject($exception);

        $this->resolve();
    }

    #[Test]
    public function an_email_outside_the_allowlist_returns_null_without_a_search(): void
    {
        $this->lookup->shouldNotReceive('findMatches');

        self::assertNull($this->resolve(email: 'x@example.com" OR email:"y@example.com'));
    }

    #[Test]
    public function an_invalid_request_failure_returns_null(): void
    {
        $this->lookup->expects('findMatches')->andThrow(new InvalidApiRequestException('HelpScout', 'Invalid request parameters'));

        self::assertNull($this->resolve());
    }

    private function resolve(int $attempts = 2, string $email = self::EMAIL): ?string
    {
        return $this->resolver->resolve(
            self::SUBMISSION_ID,
            self::ACTION_ID,
            new ActionClaimDTO(
                token: 'claim-token',
                attempts: $attempts,
                externalId: null,
                createdAt: new DateTimeImmutable(self::ACTION_CREATED_AT),
            ),
            self::submission($email),
        );
    }

    private static function submission(string $email): ContactSubmission
    {
        return new ContactSubmission(
            form: new ContactFormData(name: 'Jane Doe', email: $email, reason: ContactReason::Other, message: 'Hello'),
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: new SubmissionContext(clientTimestamp: new DateTimeImmutable(self::ACTION_CREATED_AT), ipAddress: '127.0.0.1'),
        );
    }

    private static function conversation(
        int $id,
        ?string $email = self::EMAIL,
        ?string $subject = null,
        string $createdAt = '2024-01-15T10:00:05Z',
        bool $withCustomer = true,
    ): Conversation {
        return new Conversation(
            id: $id,
            number: $id + 1000,
            subject: $subject ?? self::submission(self::EMAIL)->helpScoutSubject(),
            status: 'closed',
            mailboxId: self::MAILBOX_ID,
            createdAt: new DateTimeImmutable($createdAt),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: null,
            snooze: null,
            tags: [],
            customer: $withCustomer ? new ConversationCustomer(id: 5, firstName: 'Jane', lastName: 'Doe', email: $email) : null,
            assignee: null,
        );
    }
}
