<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Catalog\Queries\CategoryListQueryParams;
use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\PaginatedList;

/**
 * Repository for ShopWired category persistence.
 *
 * @extends RepositoryWriteInterface<Category>
 */
interface CategoryRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get all categories, ordered by sort_order.
     *
     * @return list<Category>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findAll(): array;

    /**
     * Upsert a category from webhook data.
     *
     * When $presentEmbeds is non-empty, only persists embed columns that were
     * actually present in the webhook payload (prevents overwriting with empty arrays).
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveFromWebhook(Category $category, array $presentEmbeds = []): void;

    /**
     * Delete a category by its ShopWired external ID.
     *
     * Used by `category.deleted` webhook.
     *
     * @throws RecordNotFoundException When no category found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;

    /**
     * Paginate categories with optional includes and active filtering.
     *
     * @return PaginatedList<CategoryView>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When category model data is incomplete
     */
    public function paginate(int $perPage, int $page, CategoryListQueryParams $params = new CategoryListQueryParams()): PaginatedList;

    /**
     * Find a category by external ID for the API.
     *
     * Returns any category regardless of active status (404 only if ID doesn't exist).
     *
     * @param list<CategoryInclude> $includes Requested embeds
     *
     * @throws RecordNotFoundException When no category matches the ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function findCategoryForApi(IntId $categoryId, array $includes = []): CategoryView;
}
