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
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Jobs\Conversion\ProcessQuoteConversionJob;
use DateMalformedStringException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Uploads a quote conversion to an ad platform (Google, Bing, …), resolved from
 * the passed {@see AdPlatform} via the adapter seam.
 *
 * Called by {@see ProcessQuoteConversionJob} after the action is created in pending
 * state. Idempotent — skips if action already terminal (completed or failed).
 *
 * Differs from {@see ProcessLeadConversionUseCase} in two ways:
 * - `convertedAt` is the staff-provided timestamp from the command, NOT the
 *   submission's `submittedAt` (a quote may be issued days after the form).
 * - The upload carries a monetary `value` (GBP ex-VAT) instead of `null` — the
 *   ad platform attributes revenue to the conversion.
 */
final readonly class ProcessQuoteConversionUseCase
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
     * @param float $value GBP ex-VAT amount to send to the ad platform
     * @param string $convertedAt ATOM-formatted timestamp produced by the dispatcher
     *
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable (transient — retry)
     * @throws AuthenticationExpiredException When ad platform credentials invalid (permanent)
     * @throws InvalidApiRequestException When the ad platform rejects the conversion (permanent)
     * @throws InvalidApiResponseException When the ad platform API response is malformed (permanent)
     * @throws UnsupportedConversionTypeException When the platform does not support quote conversions (permanent)
     * @throws RecordNotFoundException When the submission no longer exists
     * @throws MalformedStoredDataException When stored submission JSONB or convertedAt is corrupted
     * @throws DatabaseOperationFailedException When DB update fails (permanent)
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When the click ID is missing (permanent)
     * @throws InvalidFormatException When the stored click ID has an invalid format (permanent)
     */
    public function execute(string $submissionId, string $actionId, float $value, string $convertedAt, AdPlatform $platform): void
    {
        $this->logger->info('Processing quote conversion', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'value' => $value,
            'converted_at' => $convertedAt,
            'platform' => $platform->value,
        ]);

        if ($this->isAlreadyTerminal($submissionId, $actionId, $platform)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $submission = $this->submissionRepository->findById($submissionId);
        $adapter = $this->adapterResolver->adapterFor($platform);
        $data = self::buildConversionUploadDTO($adapter, $submission, $value, self::parseConvertedAt($convertedAt));

        $this->uploadAndMarkComplete($adapter, $submissionId, $actionId, $platform, $data);
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
            $this->logger->info('Quote conversion action already terminal — skipping', [
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
     * @throws UnsupportedConversionTypeException When the platform does not support quote conversions
     * @throws DatabaseOperationFailedException When DB update fails
     * @throws InvalidFormatException When the stored click ID has an invalid format
     */
    private function uploadAndMarkComplete(
        AdPlatformConversionAdapterInterface $adapter,
        string $submissionId,
        string $actionId,
        AdPlatform $platform,
        ConversionUploadDTO $data,
    ): void {
        $adapter->upload(ConversionType::QuoteIssued, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Quote conversion uploaded', [
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
        float $value,
        DateTimeImmutable $convertedAt,
    ): ConversionUploadDTO {
        $clickId = $adapter->extractClickId($submission->attribution);
        if ($clickId === null) {
            throw new InsufficientDataException('ContactSubmission', 'a click ID for the ad platform conversion upload');
        }

        return new ConversionUploadDTO(
            clickId: $clickId,
            email: $submission->form->email,
            convertedAt: $convertedAt,
            value: Money::exclusive($value),
            phone: $submission->form->phone,
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
