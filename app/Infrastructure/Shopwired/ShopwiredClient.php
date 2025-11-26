<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Application\Contracts\ShopwiredClientInterface;

/**
 * Shopwired e-commerce API Client.
 *
 * Handles business logic for Shopwired API interactions:
 * - Input validation
 * - Response parsing and DTO creation
 * - Domain exception wrapping for parse failures
 *
 * HTTP concerns (auth, retry, timeout, exception translation) are delegated
 * to ShopwiredHttpTransport, following the separation of concerns principle.
 *
 * Design Philosophy: "Thin SDK"
 * - No caching (implement in Application layer if needed)
 * - No business logic beyond validation and parsing
 * - Simple error handling (throw on failures)
 *
 * @template-pattern API Client (Template Pattern)
 * @see https://shopwired.readme.io/docs/getting-started Official API documentation
 */
final readonly class ShopwiredClient implements ShopwiredClientInterface
{
    private const string ENDPOINT_BUSINESS = 'business';

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * Verify API connectivity and authentication.
     *
     * Calls the /business endpoint to verify credentials work.
     * Returns 200 with business info on success.
     * Retry is disabled for connectivity checks (fail fast).
     */
    public function verifyConnectivity(): void
    {
        $this->transport->get(self::ENDPOINT_BUSINESS, retry: false);
    }
}
