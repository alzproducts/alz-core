<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON response envelope for product price update endpoints.
 *
 * Mirrors the shape of BulkUpdateResponseDTO but carries the richer
 * price-update outcome: skipped SKUs, permanent failures, and temporary failures.
 *
 * Implements Responsable so controllers return this directly.
 */
final readonly class PriceUpdateResponseDTO implements Responsable
{
    /**
     * @param list<array{sku: string, reason: string}> $skipped
     * @param list<array{sku: string|null, error: string}> $permanentFailures
     * @param list<array{sku: string|null, error: string}> $temporaryFailures
     */
    public function __construct(
        private int $total,
        private int $succeeded,
        private array $skipped,
        private array $permanentFailures,
        private array $temporaryFailures,
    ) {}

    public static function fromResult(PriceUpdateResult $result): self
    {
        return new self(
            total: $result->total,
            succeeded: $result->succeeded,
            skipped: \array_map(
                static fn(SkippedPriceUpdateResult $item): array => [
                    'sku' => $item->sku->value,
                    'reason' => $item->reason,
                ],
                $result->skipped,
            ),
            permanentFailures: self::mapFailures($result->permanentFailures),
            temporaryFailures: self::mapFailures($result->temporaryFailures),
        );
    }

    /**
     * @param list<FailedPriceUpdateResult> $failures
     *
     * @return list<array{sku: string|null, error: string}>
     */
    private static function mapFailures(array $failures): array
    {
        return \array_map(
            static fn(FailedPriceUpdateResult $item): array => [
                'sku' => $item->sku?->value,
                'error' => $item->error,
            ],
            $failures,
        );
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'total' => $this->total,
            'succeeded' => $this->succeeded,
            'skipped' => $this->skipped,
            'permanent_failures' => $this->permanentFailures,
            'temporary_failures' => $this->temporaryFailures,
        ], Response::HTTP_OK);
    }
}
