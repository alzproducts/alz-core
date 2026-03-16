<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Application\Shopwired\DTOs\WebhookDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\WebhookResponse;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Webhooks API Client.
 *
 * Retrieves registered webhooks for health monitoring.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/docs/webhooks
 */
final readonly class WebhookClient implements WebhookClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_WEBHOOKS = 'webhooks';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * List all webhooks registered with ShopWired.
     *
     * @return list<WebhookDTO>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listWebhooks(): array
    {
        $response = $this->transport->get(self::ENDPOINT_WEBHOOKS);

        /** @var list<WebhookDTO> */
        return self::parseArrayToDomain($response->json(), WebhookResponse::class);
    }
}
