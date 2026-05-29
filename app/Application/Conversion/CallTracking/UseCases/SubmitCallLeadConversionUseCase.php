<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallConversionDispatcherInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Fans out one action row per eligible platform (gclid → Google, msclkid → Bing);
 * per-platform jobs upload independently so a failure on one does not block the other.
 *
 * Action rows + annotation are inserted in a single transaction (keyed by the call id) so the
 * dashboard never sees a call lead row without its `is_potential_quote` classification — without
 * this, an `is_potential_quote=true` call would land lead_status='completed' but never surface in
 * the AwaitingQuote view. Dispatchers fire post-commit.
 */
final readonly class SubmitCallLeadConversionUseCase
{
    public function __construct(
        private CallTrackingActionRepositoryInterface $actionRepository,
        private PotentialConversionAnnotationRepositoryInterface $annotationRepository,
        private DatabaseGatewayInterface $database,
        private CallConversionDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws InsufficientDataException When the visit has neither gclid nor msclkid
     * @throws DuplicateRecordException When a lead action already exists for a platform
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(CallTrackingVisit $visit, Uuid $callId, PhoneNumberE164 $callerPhone, bool $isPotentialQuote): void
    {
        $visitId = self::requireId($visit);

        $this->logger->info('Submitting call lead conversion', [
            'visit_id' => $visitId->value,
            'call_id' => $callId->value,
            'is_potential_quote' => $isPotentialQuote,
        ]);

        $platforms = self::resolveEligiblePlatforms($visit->attribution);
        $actionIds = $this->writeActionsAndAnnotation($visitId, $callId, $isPotentialQuote, $platforms);

        $this->dispatchPerPlatform($visitId, $callerPhone, $actionIds);

        $this->logger->info('Call lead conversion dispatched', [
            'visit_id' => $visitId->value,
            'action_ids' => \array_map(static fn(Uuid $id): string => $id->value, $actionIds),
            'platforms' => \array_keys($actionIds),
        ]);
    }

    /**
     * @param  list<AdPlatform>  $platforms
     * @return array<value-of<AdPlatform>, Uuid>
     *
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function writeActionsAndAnnotation(Uuid $visitId, Uuid $callId, bool $isPotentialQuote, array $platforms): array
    {
        return $this->database->transact(function () use ($visitId, $callId, $isPotentialQuote, $platforms): array {
            $actionIds = [];
            foreach ($platforms as $platform) {
                $actionIds[$platform->value] = $this->actionRepository->create($visitId, $platform);
            }

            $this->annotationRepository->upsert(new UpsertAnnotationCommand(
                sourceId: $callId->value,
                valuesToSet: ['is_potential_quote' => $isPotentialQuote],
                columnsToClear: [],
            ));

            return $actionIds;
        });
    }

    /**
     * @param  array<value-of<AdPlatform>, Uuid>  $actionIds
     */
    private function dispatchPerPlatform(Uuid $visitId, PhoneNumberE164 $callerPhone, array $actionIds): void
    {
        if (isset($actionIds[AdPlatform::Google->value])) {
            $this->dispatcher->dispatchGoogleCallLeadConversion(
                new CallLeadConversionCommand($visitId, $actionIds[AdPlatform::Google->value], $callerPhone),
            );
        }

        if (isset($actionIds[AdPlatform::Bing->value])) {
            $this->dispatcher->dispatchBingCallLeadConversion(
                new CallLeadConversionCommand($visitId, $actionIds[AdPlatform::Bing->value], $callerPhone),
            );
        }
    }

    /**
     * @return list<AdPlatform>
     *
     * @throws InsufficientDataException When neither gclid nor msclkid is present
     */
    private static function resolveEligiblePlatforms(MarketingAttribution $attribution): array
    {
        $platforms = [];
        if ($attribution->gclid !== null) {
            $platforms[] = AdPlatform::Google;
        }
        if ($attribution->msclkid !== null) {
            $platforms[] = AdPlatform::Bing;
        }

        if ($platforms === []) {
            throw new InsufficientDataException('CallTrackingVisit', 'a gclid or msclkid for conversion tracking');
        }

        return $platforms;
    }

    private static function requireId(CallTrackingVisit $visit): Uuid
    {
        Assert::notNull($visit->id, 'CallTrackingVisit loaded from repository must have an id');

        return $visit->id;
    }
}
