<?php

declare(strict_types=1);

namespace App\Application\Mixpanel\UseCases;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate lookup table synchronization from any provider to Mixpanel.
 *
 * Generic use case that works with any LookupTableProviderInterface implementation.
 * The provider handles data fetching and transformation; this use case handles
 * the workflow: log, fetch, validate, upload, log completion.
 */
final readonly class SyncLookupTableUseCase
{
    public function __construct(
        private LookupTableProviderInterface $provider,
        private MixpanelClientInterface $mixpanel,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize lookup table from provider to Mixpanel.
     *
     * @throws ExternalServiceUnavailableException When external APIs are unavailable
     * @throws UnexpectedApiResultException When provider returns empty results
     * @throws AuthenticationExpiredException When source or destination credentials invalid
     */
    public function execute(): void
    {
        $tableKey = $this->provider->getTableKey();
        $sourceName = $this->provider->getSourceName();

        $this->logger->info('Starting lookup table sync', [
            'table_key' => $tableKey,
            'source' => $sourceName,
        ]);

        // Step 1: Fetch and transform data from source
        $rows = $this->provider->fetchRows();

        $this->logger->info('Retrieved data from source', [
            'table_key' => $tableKey,
            'source' => $sourceName,
            'row_count' => \count($rows),
        ]);

        // Step 2: Handle empty results - this indicates an API issue or misconfiguration
        if ($rows === []) {
            $this->logger->error('No data found from source - this may indicate an API issue or account misconfiguration', [
                'table_key' => $tableKey,
                'source' => $sourceName,
            ]);

            throw new UnexpectedApiResultException(
                $sourceName,
                "Expected at least one row for {$tableKey}, received empty result",
            );
        }

        // Step 3: Upload to Mixpanel Lookup Table (replaces entire table)
        $this->mixpanel->replaceLookupTable(
            $tableKey,
            $this->provider->getHeaders(),
            $rows,
        );

        $this->logger->info('Lookup table sync completed', [
            'table_key' => $tableKey,
            'source' => $sourceName,
            'rows_synced' => \count($rows),
        ]);
    }
}
