<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\CategoryWebhookParserInterface;
use App\Application\Shopwired\DTOs\WebhookCategoryResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\CategoryWebhookResponse;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Parses a ShopWired category webhook payload into a domain Category object.
 */
final readonly class ShopwiredCategoryWebhookParser implements CategoryWebhookParserInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseCategory(array $data): WebhookCategoryResultDTO
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            $response = CategoryWebhookResponse::from($data['object']);

            return new WebhookCategoryResultDTO(
                category: $response->toDomain(),
                presentEmbeds: $response->presentEmbeds(),
            );
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired category webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
