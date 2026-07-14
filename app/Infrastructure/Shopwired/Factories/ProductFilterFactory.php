<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredFilterGroupsJob;
use App\Infrastructure\Shopwired\Filters\FilterGroupRegistry;
use App\Infrastructure\Shopwired\Filters\UnknownFilterGroupReporter;

/**
 * Factory for typing raw product filter values into domain objects.
 *
 * Joins raw filter data (optionNo → values map) with the FilterGroupRegistry
 * to produce typed ProductFilter instances.
 *
 * **Lifecycle**: Register with `scoped()` binding to ensure fresh instance per queue job.
 * This prevents stale filter group definitions in Octane long-running processes.
 */
final class ProductFilterFactory
{
    private ?FilterGroupRegistry $registry = null;

    public function __construct(
        private readonly FilterGroupRepositoryInterface $filterGroupRepository,
        private readonly UnknownFilterGroupReporter $reporter,
    ) {}

    /**
     * Build typed ProductFilter values from raw filter data.
     *
     * Unknown optionNo values are counted and skipped, then emitted as a single
     * per-request summary warning (may indicate filter group definitions are out
     * of sync - re-run {@see SyncShopwiredFilterGroupsJob}).
     *
     * @param array<int|string, list<string>> $rawFilters Raw filter data (optionNo => values)
     *
     * @return list<ProductFilter>
     *
     * @throws DatabaseOperationFailedException When filter group registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function fromRawFilters(array $rawFilters): array
    {
        if ($rawFilters === []) {
            return [];
        }

        $result = [];

        foreach ($rawFilters as $optionNo => $values) {
            // API returns optionNo as string keys in JSON
            $optionNoInt = (int) $optionNo;

            $definition = $this->registry()->findByOptionNo($optionNoInt);

            if ($definition === null) {
                $this->reporter->record($optionNoInt);

                continue;
            }

            $result[] = new ProductFilter($definition, $values);
        }

        return $result;
    }

    /**
     * Get the filter group registry, lazy-loading on first access.
     *
     * @throws DatabaseOperationFailedException When query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function registry(): FilterGroupRegistry
    {
        if ($this->registry === null) {
            $definitions = $this->filterGroupRepository->findAll();
            $this->registry = FilterGroupRegistry::fromDefinitions($definitions);
        }

        return $this->registry;
    }
}
