<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Validators\CustomFieldSubmissionValidator;
use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Validate and update custom fields on a product via ShopWired.
 *
 * Validates submitted key-value pairs against the custom field registry,
 * delegates to the existing fetch-merge-PUT update pattern, then re-syncs
 * the local product mirror so the saved values are immediately readable.
 */
final readonly class UpdateProductCustomFieldsUseCase
{
    public function __construct(
        private CustomFieldValueFactoryInterface $valueFactory,
        private ProductUpdateClientInterface $productUpdateClient,
        private ProductSyncService $productSync,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string|int|bool|list<string>|list<int>|null> $rawFields Custom field name => value pairs
     *
     * @throws ValidationFailedException When fields fail validation (unknown field or type mismatch)
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When the API or database is unavailable, or a connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws DatabaseOperationFailedException When custom field registry fails to load or the mirror upsert fails
     * @throws DuplicateRecordException On constraint violation
     */
    public function execute(IntId $productId, array $rawFields): void
    {
        $this->logger->info('Updating product custom fields', [
            'product_id' => $productId->value,
            'field_count' => \count($rawFields),
            'field_names' => \array_keys($rawFields),
        ]);

        (new CustomFieldSubmissionValidator($this->valueFactory, $rawFields))->validate()->orFail();

        $this->productUpdateClient->updateCustomFields($productId->value, $rawFields);

        $this->productSync->refreshById($productId->value);

        $this->logger->info('Updated product custom fields', [
            'product_id' => $productId->value,
        ]);
    }
}
