<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use App\Application\ContactSubmission\Enums\PotentialConversionSource;
use App\Application\ContactSubmission\UseCases\UpsertContactSubmissionAnnotationUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpsertContactSubmissionAnnotationUseCase::class)]
final class UpsertContactSubmissionAnnotationUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '019d9358-01fe-72c9-b123-5f452270d3c1';

    private ContactSubmissionDashboardQueryRepositoryInterface&MockInterface $dashboardQueryRepository;

    private PotentialConversionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private UpsertContactSubmissionAnnotationUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardQueryRepository = Mockery::mock(ContactSubmissionDashboardQueryRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(PotentialConversionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new UpsertContactSubmissionAnnotationUseCase(
            dashboardQueryRepository: $this->dashboardQueryRepository,
            annotationRepository: $this->annotationRepository,
            logger: $this->logger,
        );
    }

    #[Test]
    public function execute_upserts_annotation_when_row_exists(): void
    {
        $command = new UpsertAnnotationCommand(
            sourceId: self::SUBMISSION_ID,
            valuesToSet: ['is_potential_quote' => true, 'notes' => 'follow up'],
            columnsToClear: [ContactSubmissionAnnotationField::QuotedAt],
        );

        $this->dashboardQueryRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow());

        $this->annotationRepository
            ->shouldReceive('upsert')
            ->once()
            ->with($command);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Upserting contact submission annotation', [
                'source_id' => self::SUBMISSION_ID,
                'fields_set' => ['is_potential_quote', 'notes'],
                'fields_cleared' => ['quoted_at'],
            ]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Upserted contact submission annotation', [
                'source_id' => self::SUBMISSION_ID,
            ]);

        $this->useCase->execute($command);
    }

    #[Test]
    public function execute_propagates_record_not_found_and_skips_upsert_when_row_missing(): void
    {
        $command = new UpsertAnnotationCommand(
            sourceId: self::SUBMISSION_ID,
            valuesToSet: ['is_potential_quote' => true],
            columnsToClear: [],
        );

        $this->dashboardQueryRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andThrow(new RecordNotFoundException('PotentialConversion', self::SUBMISSION_ID));

        $this->annotationRepository->shouldNotReceive('upsert');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Upserting contact submission annotation', Mockery::type('array'));

        $this->logger->shouldNotReceive('info')->with('Upserted contact submission annotation', Mockery::any());

        try {
            $this->useCase->execute($command);
            self::fail('Expected RecordNotFoundException');
        } catch (RecordNotFoundException $e) {
            self::assertSame('PotentialConversion', $e->resourceType);
            self::assertSame(self::SUBMISSION_ID, $e->resourceId);
        }
    }

    private static function stubRow(): ContactSubmissionListItemDTO
    {
        return new ContactSubmissionListItemDTO(
            id: Guid::fromTrusted(self::SUBMISSION_ID),
            source: PotentialConversionSource::Form,
            name: null,
            email: null,
            reason: null,
            customerType: null,
            orderNumber: null,
            quantity: null,
            product: null,
            shopwiredCustomerId: null,
            gclid: null,
            msclkid: null,
            fbclid: null,
            utmSource: null,
            utmMedium: null,
            utmCampaign: null,
            pageUrl: null,
            createdAt: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
            helpscoutExternalId: null,
            leadStatus: null,
            quoteStatus: null,
            isPotentialQuote: null,
            notes: null,
            quotedAt: null,
            callerPhoneNumber: null,
        );
    }
}
