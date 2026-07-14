<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\QuoteConversionDetailsDTO;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Uuid;
use DateMalformedStringException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Requires a Completed LeadReceived action before a quote may be issued.
 * Quote value + staff-supplied timestamp are uploaded to each ad platform whose
 * adapter supports quote conversions and has a click ID present.
 *
 * A submission whose only click ID belongs to a platform that cannot receive
 * quotes (e.g. msclkid-only — Bing does not support quote conversions) creates
 * zero action rows and is logged, rather than dispatched to a doomed upload.
 *
 * No transaction: at most one platform supports quotes today, and the partial
 * unique index `(submission_id, action_type, ad_platform)` resolves the
 * hasCompletedAction → create race.
 */
final readonly class SubmitQuoteConversionUseCase
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private ConversionDispatcherInterface $dispatcher,
        private AdPlatformAdapterResolverService $adapterResolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param float $value GBP ex-VAT
     * @param string $convertedAt ISO-8601 / parseable date string
     *
     * @throws RecordNotFoundException
     * @throws InsufficientDataException When neither click ID is present, or no completed lead exists
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(string $submissionId, float $value, string $convertedAt): void
    {
        $this->logSubmitting($submissionId, $value, $convertedAt);

        $submission = $this->submissionRepository->findById($submissionId);

        $platforms = $this->eligiblePlatformsToDispatch($submission->attribution, ['submission_id' => $submissionId]);
        if ($platforms === []) {
            return;
        }

        $this->ensureLeadCompleted($submissionId);

        $details = new QuoteConversionDetailsDTO($value, $convertedAt);
        foreach ($platforms as $platform) {
            $this->dispatchForPlatform($submissionId, $details, $platform);
        }
    }

    private function logSubmitting(string $submissionId, float $value, string $convertedAt): void
    {
        $this->logger->info('Submitting quote conversion', [
            'submission_id' => $submissionId,
            'value' => $value,
            'converted_at' => $convertedAt,
        ]);
    }

    /**
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws MalformedStoredDataException
     */
    private function dispatchForPlatform(string $submissionId, QuoteConversionDetailsDTO $details, AdPlatform $platform): void
    {
        $actionId = $this->actionRepository->create($submissionId, ActionType::QuoteIssued, $platform);

        $this->dispatcher->dispatchQuoteConversion(self::buildCommand($submissionId, $actionId, $details, $platform));

        $this->logger->info('Quote conversion dispatched', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);
    }

    /**
     * The DTO's `#[Date]` rule already validated `$convertedAt`; a failure here
     * means validation bypass or strtotime/DateTimeImmutable parser divergence.
     *
     * @throws MalformedStoredDataException
     */
    private static function buildCommand(string $submissionId, string $actionId, QuoteConversionDetailsDTO $details, AdPlatform $platform): QuoteConversionCommand
    {
        try {
            $convertedAtTime = new DateTimeImmutable($details->convertedAt);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                'ConversionRequest',
                'converted_at must be a parseable date string',
                previous: $e,
            );
        }

        return new QuoteConversionCommand(
            submissionId: Uuid::fromTrusted($submissionId),
            actionId: Uuid::fromTrusted($actionId),
            value: Money::exclusive($details->value),
            convertedAt: $convertedAtTime,
            platform: $platform,
        );
    }

    /**
     * Platforms an upload can actually be attempted on, resolved through the
     * adapter seam. Distinguishes a hard data error (no click ID at all) from a
     * graceful skip (a click ID whose platform cannot receive a quote).
     *
     * @param array<string, mixed> $logContext
     *
     * @return list<AdPlatform> empty means nothing to dispatch (already logged)
     *
     * @throws InsufficientDataException When no ad-platform click ID is present at all
     */
    private function eligiblePlatformsToDispatch(MarketingAttribution $attribution, array $logContext): array
    {
        if ($this->adapterResolver->platformsWithClickId($attribution) === []) {
            throw new InsufficientDataException('ContactSubmission', 'a gclid or msclkid for conversion tracking');
        }

        $eligible = $this->adapterResolver->eligiblePlatforms(ConversionType::QuoteIssued, $attribution);
        if ($eligible === []) {
            $this->logger->error('No ad platform supports quote conversions — skipping upload', $logContext);

            return [];
        }

        if (\count($eligible) < \count($this->adapterResolver->platformsWithClickId($attribution))) {
            $this->logger->info('Some ad platforms with a click ID do not support quote conversions — skipping them', $logContext);
        }

        return $eligible;
    }

    /**
     * @throws InsufficientDataException When no completed LeadReceived action exists for the submission
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function ensureLeadCompleted(string $submissionId): void
    {
        if (! $this->actionRepository->hasCompletedAction($submissionId, ActionType::LeadReceived)) {
            throw new InsufficientDataException('ContactSubmission', 'a completed lead action before issuing a quote');
        }
    }
}
