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
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Uploads a lead conversion to Google Ads.
 *
 * Called by ProcessLeadConversionJob after action is created in pending state.
 * Idempotent — skips if action already terminal (completed or failed).
 */
final readonly class ProcessLeadConversionUseCase
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
     * @throws ExternalServiceUnavailableException When Google Ads/DB unavailable (transient — retry)
     * @throws AuthenticationExpiredException When Google Ads credentials invalid (permanent)
     * @throws InvalidApiRequestException When Google Ads rejects the conversion (permanent)
     * @throws RecordNotFoundException When the submission no longer exists
     * @throws MalformedStoredDataException When stored submission JSONB is corrupted
     * @throws DatabaseOperationFailedException When DB update fails (permanent)
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When gclid or submission timestamp is missing (permanent)
     */
    public function execute(string $submissionId, string $actionId): void
    {
        $this->logger->info('Processing lead conversion', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);

        if ($this->isAlreadyTerminal($submissionId, $actionId)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $submission = $this->submissionRepository->findById($submissionId);

        $this->uploadAndMarkComplete($submission, $submissionId, $actionId);
    }

    /**
     * Idempotency guard — re-runs of the job after success/failure must be no-ops.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function isAlreadyTerminal(string $submissionId, string $actionId): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Lead conversion action already terminal — skipping', [
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
     * @throws InsufficientDataException When gclid or submission timestamp is missing
     */
    private function uploadAndMarkComplete(ContactSubmission $submission, string $submissionId, string $actionId): void
    {
        $data = self::buildClickConversionData($submission);

        $this->conversionClient->uploadConversion(ConversionType::LeadReceived, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Lead conversion uploaded', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * Compose the Google Ads upload payload from the submission snapshot.
     *
     * Validates required fields — gclid was checked at submission time but could
     * theoretically be mutated; submittedAt is always set from DB created_at but
     * null-checked explicitly since the VO type is nullable.
     * Both throw InsufficientDataException so the job fails immediately (no retry).
     *
     * @throws InsufficientDataException When gclid or submission timestamp is missing
     */
    private static function buildClickConversionData(ContactSubmission $submission): ClickConversionData
    {
        $gclid = $submission->attribution->gclid;
        if ($gclid === null || $gclid === '') {
            throw new InsufficientDataException('ContactSubmission', 'a gclid for Google Ads conversion upload');
        }

        $submittedAt = $submission->submittedAt;
        if ($submittedAt === null) {
            throw new InsufficientDataException('ContactSubmission', 'a submission timestamp for conversion time');
        }

        return new ClickConversionData(
            gclid: $gclid,
            email: $submission->form->email,
            convertedAt: $submittedAt,
            value: null,
            phone: $submission->form->phone,
        );
    }
}
