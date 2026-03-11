<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Application\Shopwired\DTOs\WebhookDTO;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Webhook
 *
 * Infrastructure DTO for parsing webhook API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * @see https://shopwired.readme.io/docs/webhooks
 */
#[MapInputName(SnakeCaseMapper::class)]
final class WebhookResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $topic,
        public readonly string $address,
        public readonly bool $enabled,
        public readonly bool $verified,
    ) {}

    public function toDomain(): WebhookDTO
    {
        return new WebhookDTO(
            id: $this->id,
            topic: $this->topic,
            address: $this->address,
            enabled: $this->enabled,
            verified: $this->verified,
        );
    }
}
