<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\BrandWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\BrandWebhookParserInterface;
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
use DateTimeImmutable;

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
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        DateTimeImmutable $eventTime,
        int $webhookId,
        string $topic,
        int $subjectId,
        array $data,
    ): void {
        $intent = $this->resolver->resolve($topic);
        $webhookTopic = WebhookTopic::tryFrom($topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $topic);
        $brandId = IntId::from($subjectId);

        match ($intent) {
            BrandWebhookIntent::Deleted => $this->deleteBrandUseCase->execute(
                webhookId: $webhookId,
                brandId: $brandId,
            ),

            BrandWebhookIntent::Sync => $this->handleSync(
                eventTime: $eventTime,
                webhookId: $webhookId,
                topic: $webhookTopic,
                data: $data,
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
    private function handleSync(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, array $data): void
    {
        $result = $this->brandParser->parseBrand($data);

        $this->syncBrandUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            topic: $topic,
            brand: $result->brand,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
