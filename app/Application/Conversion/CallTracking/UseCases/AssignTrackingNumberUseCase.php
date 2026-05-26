<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingNumberRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\CallTracking\Commands\AssignTrackingNumberCommand;
use App\Application\Conversion\CallTracking\Results\AssignTrackingNumberResult;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\Exceptions\CallTrackingNumberPoolEmptyException;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateMalformedStringException;
use DateTimeImmutable;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Webmozart\Assert\Assert;

final readonly class AssignTrackingNumberUseCase
{
    public function __construct(
        private CallTrackingVisitRepositoryInterface $visitRepository,
        private CallTrackingNumberRepositoryInterface $numberRepository,
        private DatabaseGatewayInterface $dbGateway,
        private LoggerInterface $logger,
        private PhoneNumberE164 $defaultBusinessPhoneNumber,
        private int $attributionWindowHours,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DateMalformedStringException If the configured window-hours value produces a bad interval string
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException If a stored phone number or click-ID dedup row bypasses VO guards
     * @throws RuntimeException If the visit-repository gateway returns an unexpected insert result
     */
    public function execute(AssignTrackingNumberCommand $command): AssignTrackingNumberResult
    {
        $this->logRequest($command);

        if (! $command->marketingConsentGranted) {
            return $this->defaultResult('Marketing consent denied, returning default tracking number');
        }

        $deduped = $this->dedupedResultFor($command->attribution);
        if ($deduped !== null) {
            return $deduped;
        }

        $pool = $this->numberRepository->findAllActive();
        if ($pool === []) {
            return $this->poolEmptyResult($command);
        }

        return $this->rotateAndPersist($command, $pool);
    }

    private function poolEmptyResult(AssignTrackingNumberCommand $command): AssignTrackingNumberResult
    {
        $exception = new CallTrackingNumberPoolEmptyException(
            hadClickId: $command->attribution->primaryClickId() !== null,
            attributionWindowHours: $this->attributionWindowHours,
        );

        $this->logger->error($exception->getMessage(), $exception->context());
        \report($exception);

        return new AssignTrackingNumberResult($this->defaultBusinessPhoneNumber, null);
    }

    private function logRequest(AssignTrackingNumberCommand $command): void
    {
        $this->logger->info('Tracking number assignment requested', [
            'has_consent' => $command->marketingConsentGranted,
            'has_gclid' => $command->attribution->gclid !== null,
            'has_msclkid' => $command->attribution->msclkid !== null,
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DateMalformedStringException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException
     */
    private function dedupedResultFor(MarketingAttribution $attribution): ?AssignTrackingNumberResult
    {
        $clickId = $attribution->primaryClickId();
        if ($clickId === null) {
            return null;
        }

        $after = new DateTimeImmutable("-{$this->attributionWindowHours} hours");
        $existing = $this->visitRepository->findRecentByClickId($clickId, $after);

        return $existing === null ? null : $this->reuseExistingVisit($existing);
    }

    private function reuseExistingVisit(CallTrackingVisit $existing): AssignTrackingNumberResult
    {
        Assert::notNull($existing->id);

        $this->logger->info('Reusing tracking number from recent visit within attribution window', [
            'visit_id' => $existing->id->value,
        ]);

        return new AssignTrackingNumberResult($existing->trackingNumberShown, $existing->id);
    }

    /**
     * @param non-empty-list<PhoneNumberE164> $pool
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException
     */
    private function rotateAndPersist(AssignTrackingNumberCommand $command, array $pool): AssignTrackingNumberResult
    {
        return $this->dbGateway->transact(function () use ($command, $pool): AssignTrackingNumberResult {
            $selected = $this->selectFromPool($pool);
            $visit = self::buildVisit($command, $selected);
            $visitId = $this->visitRepository->save($visit);

            $this->logger->info('Tracking number assigned and visit persisted', [
                'visit_id' => $visitId->value,
            ]);

            return new AssignTrackingNumberResult($selected, $visitId);
        });
    }

    /**
     * @param non-empty-list<PhoneNumberE164> $pool
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function selectFromPool(array $pool): PhoneNumberE164
    {
        $index = $this->numberRepository->incrementAndGetCounter() % \count($pool);

        return $pool[$index] ?? throw new LogicException('Pool index out of bounds — unreachable given modulo math.');
    }

    private static function buildVisit(AssignTrackingNumberCommand $command, PhoneNumberE164 $selected): CallTrackingVisit
    {
        return new CallTrackingVisit(
            attribution: $command->attribution,
            marketingConsentGranted: $command->marketingConsentGranted,
            trackingNumberShown: $selected,
            ipAddress: $command->ipAddress,
            userAgent: $command->userAgent,
        );
    }

    private function defaultResult(string $reason): AssignTrackingNumberResult
    {
        $this->logger->info($reason);

        return new AssignTrackingNumberResult($this->defaultBusinessPhoneNumber, null);
    }
}
