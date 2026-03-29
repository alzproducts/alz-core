<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Shopwired\Webhooks;

use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\Services\HandleOrderWebhookService;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Shopwired\Webhooks\DTOs\WebhookEnvelopeDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives all ShopWired order-related webhook events and routes them
 * to the appropriate use case based on event topic.
 *
 * Authentication is handled upstream by VerifyShopwiredWebhookSignature middleware.
 * No try-catch — exceptions bubble to the global handler.
 */
final readonly class ShopwiredWebhookOrderController
{
    public function __construct(private HandleOrderWebhookService $service) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws ResourceNotFoundException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $envelope = WebhookEnvelopeDTO::from($request->all());
        $this->service->execute(new RawWebhookPayloadDTO(
            eventTime: $envelope->timestamp,
            webhookId: $envelope->event->id,
            topic: $envelope->event->topic,
            subjectId: $envelope->event->subjectId,
            data: $envelope->event->data,
        ));

        return new JsonResponse(status: 200);
    }
}
