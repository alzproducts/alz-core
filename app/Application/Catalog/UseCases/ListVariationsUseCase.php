<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Application\Contracts\Catalog\VariationQueryRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * List variations as first-class catalog rows with denormalized parent context.
 *
 * @see VariationQueryRepositoryInterface::paginate()
 */
final readonly class ListVariationsUseCase
{
    public function __construct(
        private VariationQueryRepositoryInterface $variationRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<VariationListItem>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(VariationListQueryParams $query): PaginatedList
    {
        $this->logger->info('Listing variations', [
            'page' => $query->pagination->page,
            'per_page' => $query->pagination->perPage,
            'includes' => $query->includes,
            'sort' => $query->sortField?->value,
            'direction' => $query->sortDirection->value,
        ]);

        $result = $this->variationRepository->paginate($query);

        $this->logger->info('Listed variations', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
