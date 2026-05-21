<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Enums\CreditTier;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;

final readonly class SetProductCreditTierLabelUseCase
{
    public function __construct(
        private ProductUpdateClientInterface $updateClient,
    ) {}

    /**
     * @throws ResourceNotAvailableException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function execute(IntId $productId, ?CreditTier $tier): void
    {
        $this->updateClient->updateCustomFields($productId->value, [
            CreditTier::FIELD => $tier?->value,
        ]);
    }
}
