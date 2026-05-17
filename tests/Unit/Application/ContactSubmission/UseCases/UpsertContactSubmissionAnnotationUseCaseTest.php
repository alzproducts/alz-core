<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\ContactSubmission\UseCases\UpsertContactSubmissionAnnotationUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\Exceptions\Api\RecordNotFoundException;
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

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private UpsertContactSubmissionAnnotationUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(ContactSubmissionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new UpsertContactSubmissionAnnotationUseCase(
            submissionRepository: $this->submissionRepository,
            annotationRepository: $this->annotationRepository,
            logger: $this->logger,
        );
    }

    #[Test]
    public function execute_upserts_annotation_when_submission_exists(): void
    {
        $command = new UpsertAnnotationCommand(
            contactSubmissionId: self::SUBMISSION_ID,
            valuesToSet: ['is_potential_quote' => true, 'notes' => 'follow up'],
            columnsToClear: [ContactSubmissionAnnotationField::QuotedAt],
        );

        $this->submissionRepository
            ->shouldReceive('existsById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturnTrue();

        $this->annotationRepository
            ->shouldReceive('upsert')
            ->once()
            ->with($command);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Upserting contact submission annotation', [
                'contact_submission_id' => self::SUBMISSION_ID,
                'fields_set' => ['is_potential_quote', 'notes'],
                'fields_cleared' => ['quoted_at'],
            ]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Upserted contact submission annotation', [
                'contact_submission_id' => self::SUBMISSION_ID,
            ]);

        $this->useCase->execute($command);
    }

    #[Test]
    public function execute_throws_record_not_found_and_skips_upsert_when_submission_missing(): void
    {
        $command = new UpsertAnnotationCommand(
            contactSubmissionId: self::SUBMISSION_ID,
            valuesToSet: ['is_potential_quote' => true],
            columnsToClear: [],
        );

        $this->submissionRepository
            ->shouldReceive('existsById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturnFalse();

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
            self::assertSame('ContactSubmission', $e->resourceType);
            self::assertSame(self::SUBMISSION_ID, $e->resourceId);
        }
    }
}
