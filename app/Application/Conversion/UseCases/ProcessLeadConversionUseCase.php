<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Uploads a lead conversion to an ad platform (Google, Bing, …), resolved from
 * the passed {@see AdPlatform} via the adapter seam.
 *
 * Called by ProcessLeadConversionJob after the action is created in pending state.
 * Idempotent — skips if action already terminal (completed or failed).
 */
final readonly class ProcessLeadConversionUseCase
{
    /**
     * Sentinel passed to `markCompleted()` since ad platforms return no receipt ID for
     * uploaded conversions — the action row still requires a non-null external reference.
     */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private AdPlatformAdapterResolverService $adapterResolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable (transient — retry)
     * @throws AuthenticationExpiredException When ad platform credentials invalid (permanent)
     * @throws InvalidApiRequestException When the ad platform rejects the conversion (permanent)
     * @throws InvalidApiResponseException When the ad platform API response is malformed (permanent)
     * @throws UnsupportedConversionTypeException When the platform does not support lead conversions (permanent)
     * @throws RecordNotFoundException When the submission no longer exists
     * @throws MalformedStoredDataException When stored submission JSONB is corrupted
     * @throws DatabaseOperationFailedException When DB update fails (permanent)
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When the click ID or submission timestamp is missing (permanent)
     * @throws InvalidFormatException When the stored click ID has an invalid format (permanent)
     */
    public function execute(string $submissionId, string $actionId, AdPlatform $platform): void
    {
        $this->logger->info('Processing lead conversion', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);

        if ($this->isAlreadyTerminal($submissionId, $actionId, $platform)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $submission = $this->submissionRepository->findById($submissionId);
        $adapter = $this->adapterResolver->adapterFor($platform);

        $this->uploadAndMarkComplete($adapter, $submission, $submissionId, $actionId, $platform);
    }

    /**
     * Idempotency guard — re-runs of the job after success/failure must be no-ops.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function isAlreadyTerminal(string $submissionId, string $actionId, AdPlatform $platform): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Lead conversion action already terminal — skipping', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
                'platform' => $platform->value,
            ]);
        }

        return $isTerminal;
    }

    /**
     * Upload to the ad platform then mark the action complete.
     *
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable
     * @throws AuthenticationExpiredException When ad platform credentials invalid
     * @throws InvalidApiRequestException When the ad platform rejects the conversion
     * @throws InvalidApiResponseException When the ad platform API response is malformed
     * @throws UnsupportedConversionTypeException When the platform does not support lead conversions
     * @throws DatabaseOperationFailedException When DB update fails
     * @throws InsufficientDataException When the click ID or submission timestamp is missing
     * @throws InvalidFormatException When the stored click ID has an invalid format
     */
    private function uploadAndMarkComplete(
        AdPlatformConversionAdapterInterface $adapter,
        ContactSubmission $submission,
        string $submissionId,
        string $actionId,
        AdPlatform $platform,
    ): void {
        $data = self::buildConversionUploadDTO($adapter, $submission);

        $adapter->upload(ConversionType::LeadReceived, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Lead conversion uploaded', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);
    }

    /**
     * @throws InsufficientDataException
     */
    private static function buildConversionUploadDTO(
        AdPlatformConversionAdapterInterface $adapter,
        ContactSubmission $submission,
    ): ConversionUploadDTO {
        $clickId = $adapter->extractClickId($submission->attribution);
        if ($clickId === null) {
            throw new InsufficientDataException('ContactSubmission', 'a click ID for the ad platform conversion upload');
        }

        $submittedAt = $submission->submittedAt;
        if ($submittedAt === null) {
            throw new InsufficientDataException('ContactSubmission', 'a submission timestamp for conversion time');
        }

        return new ConversionUploadDTO(
            clickId: $clickId,
            email: $submission->form->email,
            convertedAt: $submittedAt,
            value: null,
            phone: $submission->form->phone,
        );
    }
}
