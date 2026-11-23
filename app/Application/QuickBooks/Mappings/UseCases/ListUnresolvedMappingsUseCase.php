<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class ListUnresolvedMappingsUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksRealmInterface $realm,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<MappingKey>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): array
    {
        $this->logger->info('Listing unresolved QuickBooks mappings');
        $keys = $this->mappings->unresolvedKeys($this->realm->realmId());
        $this->logger->info('Listed unresolved QuickBooks mappings', ['count' => \count($keys)]);

        return $keys;
    }
}
