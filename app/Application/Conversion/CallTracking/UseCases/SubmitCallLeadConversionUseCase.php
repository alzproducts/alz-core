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
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Conversion\Enums\ConversionType;
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
        private AdPlatformAdapterResolverService $adapterResolver,
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
        $this->logSubmitting($visitId, $callId, $isPotentialQuote);

        $platforms = $this->eligiblePlatformsToDispatch(
            $visit->attribution,
            ['visit_id' => $visitId->value, 'call_id' => $callId->value],
        );
        if ($platforms === []) {
            return;
        }

        $actionIds = $this->writeActionsAndAnnotation($visitId, $callId, $isPotentialQuote, $platforms);

        $this->dispatchPerPlatform($visitId, $callerPhone, $platforms, $actionIds);

        $this->logDispatched($visitId, $actionIds);
    }

    private function logSubmitting(Uuid $visitId, Uuid $callId, bool $isPotentialQuote): void
    {
        $this->logger->info('Submitting call lead conversion', [
            'visit_id' => $visitId->value,
            'call_id' => $callId->value,
            'is_potential_quote' => $isPotentialQuote,
        ]);
    }

    /**
     * @param array<value-of<AdPlatform>, Uuid> $actionIds
     */
    private function logDispatched(Uuid $visitId, array $actionIds): void
    {
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
     * @param  list<AdPlatform>                    $platforms
     * @param  array<value-of<AdPlatform>, Uuid>   $actionIds
     */
    private function dispatchPerPlatform(Uuid $visitId, PhoneNumberE164 $callerPhone, array $platforms, array $actionIds): void
    {
        foreach ($platforms as $platform) {
            $actionId = $actionIds[$platform->value] ?? null;
            if ($actionId === null) {
                continue;
            }

            $this->dispatcher->dispatchCallLeadConversion(new CallLeadConversionCommand(
                $visitId,
                $actionId,
                $callerPhone,
                $platform,
            ));
        }
    }

    /**
     * Platforms an upload can actually be attempted on, resolved through the
     * adapter seam. Distinguishes a hard data error (no click ID at all) from a
     * graceful skip (a click ID whose platform cannot receive this conversion).
     *
     * @param array<string, mixed> $logContext
     *
     * @return list<AdPlatform> empty means nothing to dispatch (already logged)
     *
     * @throws InsufficientDataException When no ad-platform click ID is present at all
     */
    private function eligiblePlatformsToDispatch(MarketingAttribution $attribution, array $logContext): array
    {
        $withClickId = $this->adapterResolver->platformsWithClickId($attribution);
        if ($withClickId === []) {
            throw new InsufficientDataException('CallTrackingVisit', 'a gclid or msclkid for conversion tracking');
        }

        $eligible = $this->adapterResolver->eligiblePlatforms(ConversionType::LeadReceived, $attribution);
        if ($eligible === []) {
            $this->logger->error('No ad platform supports this conversion — skipping upload', $logContext);

            return [];
        }

        if (\count($eligible) < \count($withClickId)) {
            $this->logger->info('Some ad platforms with a click ID do not support this conversion — skipping them', $logContext);
        }

        return $eligible;
    }

    private static function requireId(CallTrackingVisit $visit): Uuid
    {
        Assert::notNull($visit->id, 'CallTrackingVisit loaded from repository must have an id');

        return $visit->id;
    }
}
