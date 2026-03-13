<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Shopwired\DTOs\StockChangeDTO;
use App\Application\Shopwired\DTOs\WebhookProductResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Responses\ProductStockChangedResponse;
use App\Infrastructure\Shopwired\Responses\ProductWebhookResponse;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Parses a ShopWired product webhook payload into a domain Product object.
 */
final readonly class ShopwiredProductWebhookParser implements ProductWebhookParserInterface
{
    public function __construct(private ProductDomainFactory $factory) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseProduct(array $data): WebhookProductResultDTO
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            $response = ProductWebhookResponse::from($data['object']);

            return new WebhookProductResultDTO(
                product: $this->factory->fromWebhookResponse($response),
                presentEmbeds: $response->presentEmbeds(),
            );
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired product webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseStockChange(array $data): StockChangeDTO
    {
        try {
            $response = ProductStockChangedResponse::from($data);

            return new StockChangeDTO(
                sku: $response->sku,
                isVariation: $response->isVariation,
                newQuantity: $response->newQuantity,
            );
        } catch (TypeError $e) {
            Log::error('ShopWired product stock webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
