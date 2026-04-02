<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Top-level response DTO for the v2 GetOrders endpoint.
 *
 * Pagination wrapper — not DomainConvertible.
 * Contains both open and processed order arrays and pagination token.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class GetOrdersApiResponse extends Data
{
    /**
     * @param list<OrderResponse>|null $openOrders
     * @param list<OrderResponse>|null $processedOrders
     */
    public function __construct(
        public readonly int $totalOrders,
        public readonly ?string $nextSearchToken,
        #[DataCollectionOf(OrderResponse::class)]
        public readonly ?array $openOrders,
        #[DataCollectionOf(OrderResponse::class)]
        public readonly ?array $processedOrders,
    ) {}
}
