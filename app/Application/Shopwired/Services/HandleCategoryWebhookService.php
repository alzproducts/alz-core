<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CategoryWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookParserInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\DeleteCategoryUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCategoryUseCase;
use App\Domain\Catalog\Category\Enums\CategoryWebhookIntent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Routes category webhook events to the appropriate use case.
 */
final readonly class HandleCategoryWebhookService
{
    public function __construct(
        private SyncCategoryUseCase $syncCategoryUseCase,
        private DeleteCategoryUseCase $deleteCategoryUseCase,
        private CategoryWebhookParserInterface $categoryParser,
        private CategoryWebhookEventResolverInterface $resolver,
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
        $categoryId = IntId::from($subjectId);

        match ($intent) {
            CategoryWebhookIntent::Deleted => $this->deleteCategoryUseCase->execute(
                webhookId: $webhookId,
                categoryId: $categoryId,
            ),

            CategoryWebhookIntent::Sync => $this->handleSync(
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
        $result = $this->categoryParser->parseCategory($data);

        $this->syncCategoryUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            topic: $topic,
            category: $result->category,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
