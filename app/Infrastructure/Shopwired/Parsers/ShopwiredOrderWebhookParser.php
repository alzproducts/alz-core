<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\OrderWebhookParserInterface;
use App\Application\Shopwired\DTOs\WebhookOrderRefundResultDTO;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Shopwired\Responses\OrderRefundCreatedResponse;
use App\Infrastructure\Shopwired\Responses\OrderResponse;
use App\Infrastructure\Shopwired\Responses\OrderStatusChangedResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Parses ShopWired order webhook payloads into domain objects.
 */
final readonly class ShopwiredOrderWebhookParser implements OrderWebhookParserInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseOrder(array $data): Order
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            return OrderResponse::from($data['object'])->toDomain();
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired order webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the status name is unrecognised or the payload is malformed
     */
    public function parseOrderStatus(array $data): OrderStatus
    {
        try {
            /** @var array{newStatus: array<string, mixed>} $data */
            return OrderStatusChangedResponse::from($data['newStatus'])->toDomain();
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired order status webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseOrderRefund(array $data): WebhookOrderRefundResultDTO
    {
        try {
            if (!\array_key_exists('object', $data)) {
                throw new InvalidApiResponseException('ShopWired', previous: new RuntimeException('Missing "object" key in refund webhook payload'));
            }

            /** @var array{object: array<string, mixed>} $data */
            $response = OrderRefundCreatedResponse::from($data['object']);

            return new WebhookOrderRefundResultDTO(
                orderId: IntId::from($response->orderId),
                refund: $response->toDomain(),
            );
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired order refund webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload is missing 'object.id' or 'id' is not an integer
     */
    public function parseRefundExternalId(array $data): IntId
    {
        if (!\array_key_exists('object', $data) || !\is_array($data['object'])) {
            throw new InvalidApiResponseException('ShopWired', previous: new RuntimeException('Missing "object" key in refund delete webhook payload'));
        }

        /** @var array<string, mixed> $object */
        $object = $data['object'];

        if (!\array_key_exists('id', $object) || !\is_int($object['id'])) {
            throw new InvalidApiResponseException('ShopWired', previous: new RuntimeException('Missing or non-integer "id" in refund delete webhook payload'));
        }

        try {
            return IntId::from($object['id']);
        } catch (InvalidArgumentException $e) {
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
