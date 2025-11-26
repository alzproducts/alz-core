<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Order\ValueObjects\PaymentMethod;

/**
 * Contract for Shopwired e-commerce API client.
 *
 * This interface defines the boundary between Application and Infrastructure
 * for Shopwired API operations. Implementation handles HTTP communication,
 * authentication, and response parsing.
 *
 * @template-pattern API Client Interface
 */
interface ShopwiredClientInterface
{
    /**
     * Verify API connectivity and authentication.
     *
     * Performs a lightweight request to validate credentials without
     * business logic side effects. Used for health checks and diagnostics.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or auth fails
     */
    public function verifyConnectivity(): void;

    /**
     * List available payment methods.
     *
     * @return list<PaymentMethod>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function listPaymentMethods(): array;
}
