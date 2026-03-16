<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\BrandWebhookParserInterface;
use App\Application\Shopwired\DTOs\WebhookBrandResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\BrandWebhookResponse;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Parses a ShopWired brand webhook payload into a domain Brand object.
 */
final readonly class ShopwiredBrandWebhookParser implements BrandWebhookParserInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseBrand(array $data): WebhookBrandResultDTO
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            $response = BrandWebhookResponse::from($data['object']);

            return new WebhookBrandResultDTO(
                brand: $response->toDomain(),
                presentEmbeds: $response->presentEmbeds(),
            );
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired brand webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
