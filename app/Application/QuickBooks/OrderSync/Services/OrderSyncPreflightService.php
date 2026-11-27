<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;

/**
 * What must hold before any order is synced. It makes no QuickBooks call.
 */
final readonly class OrderSyncPreflightService
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksRealmInterface $realm,
        private QuickBooksMappingRepositoryInterface $mappings,
    ) {}

    /**
     * The connection is checked before the mappings.
     *
     * @return string the realm id the sync is bound to
     *
     * @throws AuthenticationExpiredException when QuickBooks is not connected
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksMappingsUnresolvedException
     */
    public function realmReadyToSync(): string
    {
        $this->tokens->assertConnected();
        $realmId = $this->realm->realmId();
        $this->assertMappingsResolved($realmId);

        return $realmId;
    }

    /**
     * Stops a queued run posting to another company after the configured one changed.
     *
     * @throws InvalidConfigurationException when the run's company is no longer the configured one
     */
    public function assertSameCompany(string $realmId): void
    {
        if ($this->realm->realmId() !== $realmId) {
            throw new InvalidConfigurationException('QUICKBOOKS_REALM_ID', 'The run was requested for another company');
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksMappingsUnresolvedException
     */
    private function assertMappingsResolved(string $realmId): void
    {
        $unresolved = $this->mappings->unresolvedKeys($realmId);
        if ($unresolved !== []) {
            throw new QuickBooksMappingsUnresolvedException(\array_map(static fn(MappingKey $key): string => $key->value, $unresolved));
        }
    }
}
