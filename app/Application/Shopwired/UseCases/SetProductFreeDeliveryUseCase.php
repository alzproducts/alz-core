<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;

/**
 * Set free delivery designation on a single ShopWired product.
 *
 * Resolves the product identifier (SKU or ID) to a parent product ID,
 * then updates the free_delivery custom field via the API.
 *
 * Exceptions propagate to the caller (job middleware handles retry/fail decisions).
 */
final readonly class SetProductFreeDeliveryUseCase
{
    private const string CUSTOM_FIELD_NAME = 'free_delivery';

    public function __construct(
        private ProductIdentifierResolverInterface $resolver,
        private ProductUpdateClientInterface $updateClient,
    ) {}

    /**
     * @throws ProductIdentifierResolutionException When identifier cannot be resolved
     * @throws PermanentApiFailure When non-retryable API failure occurs
     * @throws TransientApiFailure When API is unavailable
     * @throws DatabaseOperationFailedException On database query failure
     */
    public function execute(SetFreeDeliveryCommand $command): void
    {
        $productId = $this->resolver->resolveToParentProductId($command->identifier);

        $this->updateClient->updateCustomFields($productId, [
            self::CUSTOM_FIELD_NAME => $command->freeDeliveryType->toStringOrEmpty(),
        ]);
    }
}
