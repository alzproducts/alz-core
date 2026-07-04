<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SyncMarginTierLabelUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Daily orchestrator: query active products whose custom_label_1 margin-tier
 * label diverges from the band computed against catalog.margin_tier_thresholds,
 * and dispatch per-product label updates.
 */
final class SyncMarginTierLabelJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /** @var array<int> */
    public array $backoff = [30, 60];

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleDatabaseExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addMinutes(45)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function handle(SyncMarginTierLabelUseCase $useCase): void
    {
        $useCase->execute();
    }
}
