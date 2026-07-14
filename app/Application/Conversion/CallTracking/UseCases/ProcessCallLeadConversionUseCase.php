<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
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
 * Uploads a call-sourced lead conversion to an ad platform (Google, Bing, …),
 * resolved from the passed {@see AdPlatform} via the adapter seam.
 *
 * Called by ProcessCallLeadConversionJob after the action is created in pending
 * state. Idempotent — terminal re-runs are no-ops.
 */
final readonly class ProcessCallLeadConversionUseCase
{
    /** Ad platforms return no receipt ID for uploaded conversions; sentinel keeps `external_id` populated. */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private CallTrackingVisitRepositoryInterface $visitRepository,
        private CallTrackingActionRepositoryInterface $actionRepository,
        private AdPlatformAdapterResolverService $adapterResolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable (transient — retry)
     * @throws AuthenticationExpiredException When ad platform credentials invalid (permanent)
     * @throws InvalidApiRequestException When the ad platform rejects the conversion (permanent)
     * @throws InvalidApiResponseException When the ad platform API response is malformed (permanent)
     * @throws UnsupportedConversionTypeException When the platform does not support lead conversions (permanent)
     * @throws RecordNotFoundException When the visit no longer exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When the click ID or visit timestamp is missing (permanent)
     * @throws InvalidFormatException When the stored click ID has an invalid format (permanent)
     */
    public function execute(string $visitId, string $actionId, string $callerPhone, AdPlatform $platform): void
    {
        $this->logger->info('Processing call lead conversion', [
            'visit_id' => $visitId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);

        if ($this->isAlreadyTerminal($visitId, $actionId, $platform)) {
            return;
        }

        $this->actionRepository->incrementAttempts($actionId);
        $this->actionRepository->markProcessing($actionId);

        $visit = $this->visitRepository->findById(Uuid::fromTrusted($visitId));
        $phone = PhoneNumberE164::from($callerPhone);
        $adapter = $this->adapterResolver->adapterFor($platform);

        $this->uploadAndMarkComplete($adapter, $visit, $phone, $visitId, $actionId, $platform);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function isAlreadyTerminal(string $visitId, string $actionId, AdPlatform $platform): bool
    {
        $isTerminal = $this->actionRepository->getStatus($actionId)?->isTerminal() === true;

        if ($isTerminal) {
            $this->logger->info('Call lead conversion action already terminal — skipping', [
                'visit_id' => $visitId,
                'action_id' => $actionId,
                'platform' => $platform->value,
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
    private function uploadAndMarkComplete(
        AdPlatformConversionAdapterInterface $adapter,
        CallTrackingVisit $visit,
        PhoneNumberE164 $phone,
        string $visitId,
        string $actionId,
        AdPlatform $platform,
    ): void {
        $data = self::buildConversionUploadDTO($adapter, $visit, $phone);

        $adapter->upload(ConversionType::LeadReceived, $data);

        $this->actionRepository->markCompleted($actionId, self::COMPLETION_RECEIPT);

        $this->logger->info('Call lead conversion uploaded', [
            'visit_id' => $visitId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);
    }

    /**
     * @throws InsufficientDataException
     */
    private static function buildConversionUploadDTO(
        AdPlatformConversionAdapterInterface $adapter,
        CallTrackingVisit $visit,
        PhoneNumberE164 $phone,
    ): ConversionUploadDTO {
        $clickId = $adapter->extractClickId($visit->attribution);
        if ($clickId === null) {
            throw new InsufficientDataException('CallTrackingVisit', 'a click ID for the ad platform conversion upload');
        }

        $createdAt = $visit->createdAt;
        if ($createdAt === null) {
            throw new InsufficientDataException('CallTrackingVisit', 'a visit timestamp for conversion time');
        }

        return new ConversionUploadDTO(
            clickId: $clickId,
            email: null,
            convertedAt: $createdAt,
            value: null,
            phone: $phone->value,
        );
    }
}
