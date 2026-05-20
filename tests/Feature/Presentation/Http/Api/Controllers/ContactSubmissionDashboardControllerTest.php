<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\ContactSubmission\UseCases\ListContactSubmissionsByViewUseCase;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmissionListItem;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\PaginatedList;
use App\Presentation\Http\Api\Controllers\ContactSubmissionDashboardController;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ContactSubmissionDashboardController::class)]
final class ContactSubmissionDashboardControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private ListContactSubmissionsByViewUseCase&MockInterface $listByViewUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->listByViewUseCase = Mockery::mock(ListContactSubmissionsByViewUseCase::class);
        $this->app->instance(ListContactSubmissionsByViewUseCase::class, $this->listByViewUseCase);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: ContactSubmissionView}>
     */
    public static function viewEndpoints(): iterable
    {
        yield 'triage' => ['/api/contact-submissions/triage', ContactSubmissionView::Triage];
        yield 'awaiting-quote' => ['/api/contact-submissions/awaiting-quote', ContactSubmissionView::AwaitingQuote];
        yield 'failed' => ['/api/contact-submissions/failed', ContactSubmissionView::Failed];
        yield 'completed' => ['/api/contact-submissions/completed', ContactSubmissionView::Completed];
    }

    #[Test]
    #[DataProvider('viewEndpoints')]
    public function unauthenticated_request_returns_401(string $path, ContactSubmissionView $_view): void
    {
        $response = $this->getJson($path);

        $response->assertStatus(401);
    }

    #[Test]
    #[DataProvider('viewEndpoints')]
    public function authenticated_but_unapproved_user_returns_403(string $path, ContactSubmissionView $_view): void
    {
        $unapproved = new AuthenticatedUser(
            id: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            email: 'pending@example.com',
            isApproved: false,
            roleName: 'admin',
        );

        $jwtStub = new class ($unapproved) {
            public function __construct(private readonly AuthenticatedUser $user) {}

            public function handle(Request $request, Closure $next): Response
            {
                $request->attributes->set('authenticated_user', $this->user);

                return $next($request);
            }
        };

        $this->app->bind(ValidateSupabaseJwtMiddleware::class, static fn() => $jwtStub);

        $response = $this->getJson($path);

        $response->assertStatus(403);
    }

    #[Test]
    #[DataProvider('viewEndpoints')]
    public function view_endpoint_dispatches_to_use_case_with_view_enum_and_default_pagination(string $path, ContactSubmissionView $view): void
    {
        $this->listByViewUseCase->shouldReceive('execute')
            ->once()
            ->withArgs(static fn(ContactSubmissionView $actualView, PageRequest $pagination): bool => $actualView === $view
                && $pagination->page === 1
                && $pagination->perPage === 50)
            ->andReturn(PaginatedList::fromPage(items: [], total: 0, perPage: 50, currentPage: 1));

        $response = $this->asApprovedUser()->getJson($path);

        $response->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    #[DataProvider('viewEndpoints')]
    public function view_endpoint_passes_explicit_pagination_through(string $path, ContactSubmissionView $view): void
    {
        $this->listByViewUseCase->shouldReceive('execute')
            ->once()
            ->withArgs(static fn(ContactSubmissionView $actualView, PageRequest $pagination): bool => $actualView === $view
                && $pagination->page === 3
                && $pagination->perPage === 25)
            ->andReturn(PaginatedList::fromPage(items: [], total: 0, perPage: 25, currentPage: 3));

        $response = $this->asApprovedUser()->getJson($path . '?page=3&per_page=25');

        $response->assertOk();
    }

    #[Test]
    public function triage_serialises_list_items_as_contact_submission_list_resource(): void
    {
        $item = new ContactSubmissionListItem(
            id: Guid::fromTrusted('d9dd22a9-c3ab-413b-8a93-25b462231a98'),
            name: 'Jane Doe',
            email: 'jane@example.com',
            reason: ContactReason::QuotationRequest,
            customerType: null,
            orderNumber: null,
            quantity: null,
            product: null,
            shopwiredCustomerId: null,
            gclid: 'cl-123',
            msclkid: null,
            fbclid: null,
            utmSource: null,
            utmMedium: null,
            utmCampaign: null,
            pageUrl: null,
            createdAt: new DateTimeImmutable('2026-05-01T10:00:00+00:00'),
            helpscoutExternalId: null,
            leadStatus: null,
            quoteStatus: null,
            isPotentialQuote: null,
            notes: null,
            quotedAt: null,
        );

        $this->listByViewUseCase->shouldReceive('execute')
            ->once()
            ->andReturn(PaginatedList::fromPage(items: [$item], total: 1, perPage: 50, currentPage: 1));

        $response = $this->asApprovedUser()->getJson('/api/contact-submissions/triage');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'd9dd22a9-c3ab-413b-8a93-25b462231a98')
            ->assertJsonPath('data.0.email', 'jane@example.com')
            ->assertJsonPath('data.0.gclid', 'cl-123')
            ->assertJsonPath('data.0.lead_status', null)
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function failed_view_returns_lead_or_quote_failed_items_as_resource_shape(): void
    {
        $leadFailed = self::makeListItem(
            id: '11111111-1111-1111-1111-111111111111',
            email: 'lead-failed@example.com',
            leadStatus: ActionStatus::Failed,
        );

        $quoteFailed = self::makeListItem(
            id: '22222222-2222-2222-2222-222222222222',
            email: 'quote-failed@example.com',
            quoteStatus: ActionStatus::Failed,
        );

        $this->listByViewUseCase->shouldReceive('execute')
            ->once()
            ->withArgs(static fn(ContactSubmissionView $view, PageRequest $pagination): bool => $view === ContactSubmissionView::Failed
                && $pagination->page === 1
                && $pagination->perPage === 50)
            ->andReturn(PaginatedList::fromPage(items: [$leadFailed, $quoteFailed], total: 2, perPage: 50, currentPage: 1));

        $response = $this->asApprovedUser()->getJson('/api/contact-submissions/failed');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.lead_status', ActionStatus::Failed->value)
            ->assertJsonPath('data.1.quote_status', ActionStatus::Failed->value);
    }

    private static function makeListItem(
        string $id,
        string $email,
        ?ActionStatus $leadStatus = null,
        ?ActionStatus $quoteStatus = null,
    ): ContactSubmissionListItem {
        return new ContactSubmissionListItem(
            id: Guid::fromTrusted($id),
            name: 'Test',
            email: $email,
            reason: ContactReason::QuotationRequest,
            customerType: null,
            orderNumber: null,
            quantity: null,
            product: null,
            shopwiredCustomerId: null,
            gclid: 'cl-1',
            msclkid: null,
            fbclid: null,
            utmSource: null,
            utmMedium: null,
            utmCampaign: null,
            pageUrl: null,
            createdAt: new DateTimeImmutable('2026-05-01T10:00:00+00:00'),
            helpscoutExternalId: null,
            leadStatus: $leadStatus,
            quoteStatus: $quoteStatus,
            isPotentialQuote: null,
            notes: null,
            quotedAt: null,
        );
    }
}
