<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use DateMalformedStringException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Synchronous entry point for marking a contact submission as a quote issued.
 *
 * Builds on the lead pipeline with two extra guards:
 * - Sequential enforcement — the submission must already have a Completed
 *   LeadReceived action (a quote cannot be issued for a non-qualified lead)
 * - Monetary value + staff-supplied conversion timestamp travel through the
 *   pipeline (quote value is uploaded to Google Ads as the conversion amount)
 *
 * The DB insert + dispatch run without a transaction — only one row is written
 * (the action) and `DuplicateRecordException` handles the idempotency case at
 * the row level. The hasCompletedAction → create race is also resolved by the
 * unique constraint (submission_id, action_type).
 */
final readonly class SubmitQuoteConversionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ConversionDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param float $value GBP ex-VAT amount sent to Google Ads
     * @param string $convertedAt ISO-8601 / parseable date string for the conversion moment
     *
     * @throws RecordNotFoundException When the submission is missing → HTTP 404
     * @throws InsufficientDataException When the submission lacks a gclid or no completed lead exists → HTTP 422
     * @throws DuplicateRecordException When a quote action already exists → HTTP 409
     * @throws MalformedStoredDataException When stored submission JSONB or convertedAt is corrupted
     * @throws DatabaseOperationFailedException When the action insert fails (permanent)
     * @throws ExternalServiceUnavailableException When the database is transiently unavailable
     */
    public function execute(string $submissionId, float $value, string $convertedAt): void
    {
        $this->logger->info('Submitting quote conversion', [
            'submission_id' => $submissionId,
            'value' => $value,
            'converted_at' => $convertedAt,
        ]);

        $submission = $this->submissionRepository->findById($submissionId);
        self::ensureGclidPresent($submission->attribution->gclid);
        $this->ensureLeadCompleted($submissionId);

        $actionId = $this->actionRepository->create($submissionId, ActionType::QuoteIssued);

        $this->dispatcher->dispatchQuoteConversion(self::buildCommand($submissionId, $actionId, $value, $convertedAt));

        $this->logger->info('Quote conversion dispatched', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * The DTO validates `converted_at` with `#[Date]`, so a parse failure here would
     * indicate either a validation bypass or a strtotime/DateTimeImmutable parser
     * divergence — translate to a domain exception that maps to a meaningful response.
     *
     * @throws MalformedStoredDataException When the date string is not parseable
     */
    private static function buildCommand(string $submissionId, string $actionId, float $value, string $convertedAt): QuoteConversionCommand
    {
        try {
            $convertedAtTime = new DateTimeImmutable($convertedAt);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                'ConversionRequest',
                'converted_at must be a parseable date string',
                previous: $e,
            );
        }

        return new QuoteConversionCommand(
            submissionId: Guid::fromTrusted($submissionId),
            actionId: Guid::fromTrusted($actionId),
            value: Money::exclusive($value),
            convertedAt: $convertedAtTime,
        );
    }

    /**
     * Treat empty-string gclid the same as null — defensive against form data quirks.
     *
     * @throws InsufficientDataException When gclid is absent or empty
     */
    private static function ensureGclidPresent(?string $gclid): void
    {
        if ($gclid === null || $gclid === '') {
            throw new InsufficientDataException('ContactSubmission', 'a gclid for conversion tracking');
        }
    }

    /**
     * @throws InsufficientDataException When no completed LeadReceived action exists for the submission
     * @throws ExternalServiceUnavailableException
     */
    private function ensureLeadCompleted(string $submissionId): void
    {
        if (! $this->actionRepository->hasCompletedAction($submissionId, ActionType::LeadReceived)) {
            throw new InsufficientDataException('ContactSubmission', 'a completed lead action before issuing a quote');
        }
    }
}
