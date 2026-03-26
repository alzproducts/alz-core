<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Clients\Traits\MergesCustomFieldsTrait;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * ShopWired Brand Update Client.
 *
 * Handles brand modification operations using fetch-merge-PUT pattern
 * to preserve existing values while updating specific fields.
 */
final readonly class BrandUpdateClient implements BrandUpdateClientInterface
{
    use MergesCustomFieldsTrait;
    private const string ENDPOINT_BRANDS = 'brands';

    public function __construct(
        private ShopwiredTransportInterface $transport,
        private BrandClientInterface $brandClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $brandId, array $customFields): void
    {
        $brand = $this->brandClient->getBrandById($brandId);
        $mergedFields = self::mergeCustomFields($brand->customFields, $customFields);

        $this->transport->put(
            self::ENDPOINT_BRANDS . '/' . $brandId,
            ['customFields' => $mergedFields],
        );
    }

}
