<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\CategoryListQueryParams;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * List categories with optional includes and active filtering.
 *
 * @see CategoryRepositoryInterface::paginate()
 */
final readonly class ListCategoriesUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<CategoryView>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When category model data is incomplete
     */
    public function execute(int $perPage, int $page, CategoryListQueryParams $params = new CategoryListQueryParams()): PaginatedList
    {
        $this->logger->info('Listing categories', [
            'page' => $page,
            'per_page' => $perPage,
            'includes' => \array_map(static fn(CategoryInclude $i): string => $i->value, $params->includes),
            'include_inactive' => $params->includeInactive,
            'is_main_category' => $params->isMainCategory,
        ]);

        $result = $this->categoryRepository->paginate($perPage, $page, $params);

        $this->logger->info('Listed categories', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
