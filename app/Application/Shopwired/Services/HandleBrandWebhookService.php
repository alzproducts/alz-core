<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\BrandWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\BrandWebhookParserInterface;
use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\DeleteBrandUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncBrandUseCase;
use App\Domain\Catalog\Brand\Enums\BrandWebhookIntent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Routes brand webhook events to the appropriate use case.
 */
final readonly class HandleBrandWebhookService
{
    public function __construct(
        private SyncBrandUseCase $syncBrandUseCase,
        private DeleteBrandUseCase $deleteBrandUseCase,
        private BrandWebhookParserInterface $brandParser,
        private BrandWebhookEventResolverInterface $resolver,
    ) {}

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(RawWebhookPayloadDTO $payload): void
    {
        $intent = $this->resolver->resolve($payload->topic);
        $webhookTopic = WebhookTopic::tryFrom($payload->topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $payload->topic);
        $brandId = IntId::from($payload->subjectId);
        $context = new WebhookContextDTO($payload->eventTime, $payload->webhookId, $webhookTopic);

        match ($intent) {
            BrandWebhookIntent::Deleted => $this->deleteBrandUseCase->execute(
                webhookId: $payload->webhookId,
                brandId: $brandId,
            ),

            BrandWebhookIntent::Sync => $this->handleSync(
                context: $context,
                data: $payload->data,
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function handleSync(WebhookContextDTO $context, array $data): void
    {
        $result = $this->brandParser->parseBrand($data);

        $this->syncBrandUseCase->execute(
            context: $context,
            brand: $result->brand,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
