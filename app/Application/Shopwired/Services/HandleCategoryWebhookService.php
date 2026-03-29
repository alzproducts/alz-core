<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CategoryWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookParserInterface;
use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
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
        $categoryId = IntId::from($payload->subjectId);
        $context = new WebhookContextDTO($payload->eventTime, $payload->webhookId, $webhookTopic);

        match ($intent) {
            CategoryWebhookIntent::Deleted => $this->deleteCategoryUseCase->execute(
                webhookId: $payload->webhookId,
                categoryId: $categoryId,
            ),

            CategoryWebhookIntent::Sync => $this->handleSync(
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
        $result = $this->categoryParser->parseCategory($data);

        $this->syncCategoryUseCase->execute(
            context: $context,
            category: $result->category,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
