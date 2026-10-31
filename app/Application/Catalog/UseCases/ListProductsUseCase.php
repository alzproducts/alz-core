<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * List products with optional includes and filters.
 *
 * @see ProductListReaderInterface::page()
 */
final readonly class ListProductsUseCase
{
    public function __construct(
        private ProductListReaderInterface $reader,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(ProductListQueryParams $query): ProductListPageResult
    {
        $this->logger->info('Listing products', [
            'page' => $query->pagination->page,
            'per_page' => $query->pagination->perPage,
            'includes' => $query->includes,
            'sort' => $query->sortField?->value,
            'direction' => $query->sortDirection->value,
        ]);

        $result = $this->reader->page($query);

        $this->logger->info('Listed products', [
            'total' => $result->total,
            'returned' => \count($result->entries),
            'fragment_hits' => self::fragmentHits($result),
        ]);

        return $result;
    }

    private static function fragmentHits(ProductListPageResult $result): int
    {
        return \count(\array_filter(
            $result->entries,
            static fn(ProductListEntryDTO $entry): bool => $entry->cachedBody !== null,
        ));
    }
}
