<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Responses\ProductResponse;
use Illuminate\Support\Facades\Log;
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
    public function parseProduct(array $data): Product
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            return $this->factory->fromResponse(ProductResponse::from($data['object']));
        } catch (TypeError $e) {
            Log::error('ShopWired product webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
