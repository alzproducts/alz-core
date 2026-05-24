<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\BingAdsConversionInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\Msclkid;
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
 * Idempotent — re-runs on a terminal action are no-ops. See {@see ProcessLeadConversionUseCase}
 * for the Google equivalent.
 */
final readonly class ProcessBingLeadConversionUseCase
{
    /**
     * Bing Ads returns no receipt ID for uploaded conversions; the action row's
     * `external_id` is NOT NULL so we store a sentinel.
     */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private BingAdsConversionInterface $conversionClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws UnsupportedConversionTypeException
     * @throws RecordNotFoundException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When msclkid or submission timestamp is missing
     * @throws InvalidFormatException When stored msclkid has an invalid format (permanent)
     */
    public function execute(string $submissionId, string $actionId): void
    {
        $this->logger->info('Processing Bing lead conversion', [
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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function isAlreadyTerminal(string $submissionId, string $actionId): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Bing lead conversion action already terminal — skipping', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
            ]);
        }

        return $isTerminal;
    }

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws UnsupportedConversionTypeException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When msclkid or submission timestamp is missing
     * @throws InvalidFormatException
     */
    private function uploadAndMarkComplete(ContactSubmission $submission, string $submissionId, string $actionId): void
    {
        $data = self::buildConversionUploadDTO($submission);

        $this->conversionClient->uploadOfflineConversion(ConversionType::LeadReceived, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Bing lead conversion uploaded', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * @throws InsufficientDataException
     * @throws InvalidFormatException
     */
    private static function buildConversionUploadDTO(ContactSubmission $submission): BingConversionUploadDTO
    {
        $msclkid = $submission->attribution->msclkid;
        if ($msclkid === null) {
            throw new InsufficientDataException('ContactSubmission', 'an msclkid for Bing Ads conversion upload');
        }

        $submittedAt = $submission->submittedAt;
        if ($submittedAt === null) {
            throw new InsufficientDataException('ContactSubmission', 'a submission timestamp for conversion time');
        }

        return new BingConversionUploadDTO(
            msclkid: Msclkid::from($msclkid)->value,
            email: $submission->form->email,
            convertedAt: $submittedAt,
            value: null,
            phone: $submission->form->phone,
        );
    }
}
