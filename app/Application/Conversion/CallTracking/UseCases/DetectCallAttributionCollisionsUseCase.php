<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingQueryRepositoryInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Conversion\CallTracking\Exceptions\CallAttributionCollisionDetectedException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Reports calls in the last 12 hours that matched more than one visit inside
 * the attribution window — the dashboard view excludes those rows, so this
 * is the only path that surfaces them to operators.
 */
final readonly class DetectCallAttributionCollisionsUseCase
{
    public function __construct(
        private CallTrackingQueryRepositoryInterface $repository,
        private ErrorReporterInterface $errorReporter,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('Detecting call attribution collisions');

        $collisions = $this->repository->findAttributionCollisions();

        foreach ($collisions as $collision) {
            $exception = new CallAttributionCollisionDetectedException(
                callId: $collision['call_id'],
                visitIds: $collision['visit_ids'],
                trackingNumber: $collision['tracking_number'],
            );

            $this->errorReporter->report($exception, $exception->context());
        }

        $this->logger->info('Call attribution collision sweep complete', [
            'collision_count' => \count($collisions),
        ]);
    }
}
