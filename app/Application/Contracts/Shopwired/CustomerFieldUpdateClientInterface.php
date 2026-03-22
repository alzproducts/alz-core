<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\ValueObjects\CustomerFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * Update individual fields on a ShopWired customer via simple PUT.
 */
interface CustomerFieldUpdateClientInterface
{
    /**
     * @throws ResourceNotAvailableException When customer not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $customerId, CustomerFieldUpdate ...$updates): void;
}
