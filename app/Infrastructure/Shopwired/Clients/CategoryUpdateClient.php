<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryUpdateClientInterface;
use App\Domain\Catalog\Category\Enums\CategoryUpdatableField;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Clients\Traits\MergesCustomFieldsTrait;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * ShopWired Category Update Client.
 *
 * Scalar fields use simple PUT. Custom fields use fetch-merge-PUT
 * to preserve existing values not included in the update.
 */
final readonly class CategoryUpdateClient implements CategoryUpdateClientInterface
{
    use MergesCustomFieldsTrait;

    private const string ENDPOINT = 'categories';

    public function __construct(
        private ShopwiredTransportInterface $transport,
        private CategoryClientInterface $categoryClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $categoryId, CategoryFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $categoryId, $payload);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $categoryId, array $customFields): void
    {
        $category = $this->categoryClient->getCategoryById($categoryId);
        $mergedFields = self::mergeCustomFields($category->customFields, $customFields);

        $this->transport->put(
            self::ENDPOINT . '/' . $categoryId,
            ['customFields' => $mergedFields],
        );
    }

    private static function mapField(CategoryUpdatableField $field): string
    {
        return match ($field) {
            CategoryUpdatableField::Title => 'title',
            CategoryUpdatableField::Description => 'description',
            CategoryUpdatableField::MetaTitle => 'metaTitle',
            CategoryUpdatableField::MetaDescription => 'metaDescription',
            CategoryUpdatableField::Description2 => 'description2',
        };
    }
}
