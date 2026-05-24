<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
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
 * Requires a Completed LeadReceived action before a quote may be issued.
 * Quote value + staff-supplied timestamp are uploaded to Google Ads.
 *
 * Bing quotes are not yet wired; the gate accepts msclkid-only submissions
 * but Google quote dispatch will mark those actions Failed downstream.
 *
 * No transaction: only one row is written, and the partial unique index
 * `(submission_id, action_type, ad_platform)` resolves the hasCompletedAction → create race.
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
     * @param float $value GBP ex-VAT
     * @param string $convertedAt ISO-8601 / parseable date string
     *
     * @throws RecordNotFoundException
     * @throws InsufficientDataException When neither click ID is present, or no completed lead exists
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(string $submissionId, float $value, string $convertedAt): void
    {
        $this->logger->info('Submitting quote conversion', [
            'submission_id' => $submissionId,
            'value' => $value,
            'converted_at' => $convertedAt,
        ]);

        $submission = $this->submissionRepository->findById($submissionId);
        self::ensureAdClickIdPresent($submission->attribution);
        $this->ensureLeadCompleted($submissionId);

        $actionId = $this->actionRepository->create($submissionId, ActionType::QuoteIssued, AdPlatform::Google);

        $this->dispatcher->dispatchQuoteConversion(self::buildCommand($submissionId, $actionId, $value, $convertedAt));

        $this->logger->info('Quote conversion dispatched', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * The DTO's `#[Date]` rule already validated `$convertedAt`; a failure here
     * means validation bypass or strtotime/DateTimeImmutable parser divergence.
     *
     * @throws MalformedStoredDataException
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
     * @throws InsufficientDataException
     */
    private static function ensureAdClickIdPresent(MarketingAttribution $attribution): void
    {
        if (! $attribution->hasGclid() && ! $attribution->hasMsclkid()) {
            throw new InsufficientDataException('ContactSubmission', 'a gclid or msclkid for conversion tracking');
        }
    }

    /**
     * @throws InsufficientDataException When no completed LeadReceived action exists for the submission
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureLeadCompleted(string $submissionId): void
    {
        if (! $this->actionRepository->hasCompletedAction($submissionId, ActionType::LeadReceived)) {
            throw new InsufficientDataException('ContactSubmission', 'a completed lead action before issuing a quote');
        }
    }
}
