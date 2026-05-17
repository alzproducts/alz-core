<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\GoogleAdsConversionClientInterface;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\ValueObjects\ClickConversionData;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Jobs\Conversion\ProcessQuoteConversionJob;
use DateMalformedStringException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Uploads a quote conversion to Google Ads.
 *
 * Called by {@see ProcessQuoteConversionJob}
 * after the action is created in pending state. Idempotent — skips if action
 * already terminal (completed or failed).
 *
 * Differs from {@see ProcessLeadConversionUseCase} in two ways:
 * - `convertedAt` is the staff-provided timestamp from the command, NOT the
 *   submission's `submittedAt` (a quote may be issued days after the form).
 * - The Google Ads upload carries a monetary `value` (GBP ex-VAT) instead of
 *   `null` — Google Ads attributes revenue to the conversion.
 */
final readonly class ProcessQuoteConversionUseCase
{
    /**
     * Sentinel passed to `markCompleted()` since Google Ads returns no receipt ID for
     * uploaded conversions — the action row still requires a non-null external reference.
     */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private GoogleAdsConversionClientInterface $conversionClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param float $value GBP ex-VAT amount to send to Google Ads
     * @param string $convertedAt ATOM-formatted timestamp produced by the dispatcher
     *
     * @throws ExternalServiceUnavailableException When Google Ads/DB unavailable (transient — retry)
     * @throws AuthenticationExpiredException When Google Ads credentials invalid (permanent)
     * @throws InvalidApiRequestException When Google Ads rejects the conversion (permanent)
     * @throws RecordNotFoundException When the submission no longer exists
     * @throws MalformedStoredDataException When stored submission JSONB or convertedAt is corrupted
     * @throws DatabaseOperationFailedException When DB update fails (permanent)
     * @throws InsufficientDataException When gclid is missing (permanent)
     */
    public function execute(string $submissionId, string $actionId, float $value, string $convertedAt): void
    {
        $this->logger->info('Processing quote conversion', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'value' => $value,
            'converted_at' => $convertedAt,
        ]);

        if ($this->isAlreadyTerminal($submissionId, $actionId)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $submission = $this->submissionRepository->findById($submissionId);
        $data = self::buildClickConversionData($submission, $value, self::parseConvertedAt($convertedAt));

        $this->uploadAndMarkComplete($submissionId, $actionId, $data);
    }

    /**
     * Idempotency guard — re-runs of the job after success/failure must be no-ops.
     *
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function isAlreadyTerminal(string $submissionId, string $actionId): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Quote conversion action already terminal — skipping', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
            ]);
        }

        return $isTerminal;
    }

    /**
     * Upload to Google Ads then mark the action complete.
     *
     * @throws ExternalServiceUnavailableException When Google Ads/DB unavailable
     * @throws AuthenticationExpiredException When Google Ads credentials invalid
     * @throws InvalidApiRequestException When Google Ads rejects the conversion
     * @throws DatabaseOperationFailedException When DB update fails
     */
    private function uploadAndMarkComplete(string $submissionId, string $actionId, ClickConversionData $data): void
    {
        $this->conversionClient->uploadConversion(ConversionType::QuoteIssued, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Quote conversion uploaded', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * Compose the Google Ads upload payload from the submission snapshot plus the
     * staff-supplied conversion value and timestamp.
     *
     * @throws InsufficientDataException When gclid is missing
     */
    private static function buildClickConversionData(
        ContactSubmission $submission,
        float $value,
        DateTimeImmutable $convertedAt,
    ): ClickConversionData {
        $gclid = $submission->attribution->gclid;
        if ($gclid === null || $gclid === '') {
            throw new InsufficientDataException('ContactSubmission', 'a gclid for Google Ads conversion upload');
        }

        return new ClickConversionData(
            gclid: $gclid,
            email: $submission->form->email,
            convertedAt: $convertedAt,
            value: Money::exclusive($value),
        );
    }

    /**
     * The convertedAt string was produced by `QueuedConversionDispatcher` formatting
     * the command's `DateTimeImmutable` as ATOM. A parse failure here means the queue
     * payload was tampered or corrupted between dispatch and execution.
     *
     * @throws MalformedStoredDataException When the string is not a parseable date
     */
    private static function parseConvertedAt(string $convertedAt): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($convertedAt);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                'ConversionJobPayload',
                'converted_at must be a parseable date string',
                previous: $e,
            );
        }
    }
}
