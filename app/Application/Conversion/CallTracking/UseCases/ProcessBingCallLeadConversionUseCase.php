<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\BingAdsConversionInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\ContactSubmission\ValueObjects\Msclkid;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Uploads a call-sourced lead conversion to Bing Ads. See {@see ProcessCallLeadConversionUseCase}
 * for the Google equivalent. Idempotent — terminal re-runs are no-ops.
 */
final readonly class ProcessBingCallLeadConversionUseCase
{
    /** Bing Ads returns no receipt ID for uploaded conversions; sentinel keeps `external_id` populated. */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private CallTrackingVisitRepositoryInterface $visitRepository,
        private CallTrackingActionRepositoryInterface $actionRepository,
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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When msclkid or visit timestamp is missing
     * @throws InvalidFormatException When stored msclkid has an invalid format (permanent)
     */
    public function execute(string $visitId, string $actionId, string $callerPhone): void
    {
        $this->logger->info('Processing Bing call lead conversion', [
            'visit_id' => $visitId,
            'action_id' => $actionId,
        ]);

        if ($this->isAlreadyTerminal($visitId, $actionId)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $visit = $this->visitRepository->findById(Uuid::fromTrusted($visitId));
        $phone = PhoneNumberE164::from($callerPhone);

        $this->uploadAndMarkComplete($visit, $phone, $visitId, $actionId);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function isAlreadyTerminal(string $visitId, string $actionId): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Bing call lead conversion action already terminal — skipping', [
                'visit_id' => $visitId,
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
     * @throws InsufficientDataException
     * @throws InvalidFormatException
     */
    private function uploadAndMarkComplete(CallTrackingVisit $visit, PhoneNumberE164 $phone, string $visitId, string $actionId): void
    {
        $data = self::buildConversionUploadDTO($visit, $phone);

        $this->conversionClient->uploadOfflineConversion(ConversionType::LeadReceived, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Bing call lead conversion uploaded', [
            'visit_id' => $visitId,
            'action_id' => $actionId,
        ]);
    }

    /**
     * @throws InsufficientDataException
     * @throws InvalidFormatException
     */
    private static function buildConversionUploadDTO(CallTrackingVisit $visit, PhoneNumberE164 $phone): BingConversionUploadDTO
    {
        $msclkid = $visit->attribution->msclkid;
        if ($msclkid === null) {
            throw new InsufficientDataException('CallTrackingVisit', 'an msclkid for Bing Ads conversion upload');
        }

        $createdAt = $visit->createdAt;
        if ($createdAt === null) {
            throw new InsufficientDataException('CallTrackingVisit', 'a visit timestamp for conversion time');
        }

        return new BingConversionUploadDTO(
            msclkid: Msclkid::from($msclkid)->value,
            email: null,
            convertedAt: $createdAt,
            value: null,
            phone: $phone->value,
        );
    }
}
