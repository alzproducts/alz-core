<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Fans out one action row per eligible platform (gclid → Google, msclkid → Bing);
 * per-platform jobs upload independently so a failure on one does not block the other.
 *
 * Action rows + annotation are inserted in a single transaction so the dashboard
 * never sees a lead row without its `is_potential_quote` classification.
 * Dispatchers fire post-commit.
 */
final readonly class SubmitLeadConversionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private PotentialConversionAnnotationRepositoryInterface $annotationRepository,
        private DatabaseGatewayInterface $database,
        private ConversionDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException
     * @throws InsufficientDataException When the submission has neither gclid nor msclkid
     * @throws DuplicateRecordException When a lead action already exists for a platform
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(Uuid $submissionId, bool $isPotentialQuote): void
    {
        $id = $submissionId->value;
        $this->logger->info('Submitting lead conversion', [
            'submission_id' => $id,
            'is_potential_quote' => $isPotentialQuote,
        ]);

        $submission = $this->submissionRepository->findById($id);
        $platforms = self::resolveEligiblePlatforms($submission->attribution);
        $actionIds = $this->writeActionsAndAnnotation($id, $isPotentialQuote, $platforms);

        $this->dispatchPerPlatform($submissionId, $actionIds);

        $this->logger->info('Lead conversion dispatched', [
            'submission_id' => $id,
            'action_ids' => $actionIds,
            'platforms' => \array_keys($actionIds),
        ]);
    }

    /**
     * @param list<AdPlatform> $platforms
     *
     * @return array<value-of<AdPlatform>, string>
     *
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function writeActionsAndAnnotation(string $submissionId, bool $isPotentialQuote, array $platforms): array
    {
        return $this->database->transact(function () use ($submissionId, $isPotentialQuote, $platforms): array {
            $actionIds = [];
            foreach ($platforms as $platform) {
                $actionIds[$platform->value] = $this->actionRepository->create(
                    $submissionId,
                    ActionType::LeadReceived,
                    $platform,
                );
            }

            $this->annotationRepository->upsert(new UpsertAnnotationCommand(
                sourceId: $submissionId,
                valuesToSet: ['is_potential_quote' => $isPotentialQuote],
                columnsToClear: [],
            ));

            return $actionIds;
        });
    }

    /**
     * @param array<value-of<AdPlatform>, string> $actionIds
     */
    private function dispatchPerPlatform(Uuid $submissionId, array $actionIds): void
    {
        if (isset($actionIds[AdPlatform::Google->value])) {
            $this->dispatcher->dispatchLeadConversion(
                new LeadConversionCommand($submissionId, Uuid::fromTrusted($actionIds[AdPlatform::Google->value])),
            );
        }

        if (isset($actionIds[AdPlatform::Bing->value])) {
            $this->dispatcher->dispatchBingLeadConversion(
                new LeadConversionCommand($submissionId, Uuid::fromTrusted($actionIds[AdPlatform::Bing->value])),
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
            throw new InsufficientDataException('ContactSubmission', 'a gclid or msclkid for conversion tracking');
        }

        return $platforms;
    }
}
