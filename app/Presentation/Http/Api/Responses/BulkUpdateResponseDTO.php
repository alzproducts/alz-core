<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Catalog\Results\FailedCostPriceUpdateResult;
use App\Application\Results\BatchUpdateResult;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Universal JSON response envelope for bulk update API endpoints.
 *
 * Guarantees a consistent top-level shape:
 *
 *     {"total": 5, "succeeded": 3, "failures": [{"sku": "...", "error": "..."}]}
 *
 * Implements Responsable so controllers can return this directly.
 * Per-result-type factories handle the domain-to-response mapping.
 */
final readonly class BulkUpdateResponseDTO implements Responsable
{
    /**
     * @param int<0, max> $total Total items submitted
     * @param int<0, max> $succeeded Items that succeeded
     * @param list<array{sku: string, error: string}> $failures Per-item failures
     */
    public function __construct(
        private int $total,
        private int $succeeded,
        private array $failures,
    ) {}

    public static function fromCostPriceResult(CostPriceUpdateResult $result): self
    {
        return new self(
            total: $result->total,
            succeeded: $result->succeeded,
            failures: \array_map(
                static fn(FailedCostPriceUpdateResult $f): array => [
                    'sku' => $f->sku->value,
                    'error' => $f->error,
                ],
                $result->failures,
            ),
        );
    }

    /**
     * Build the response from a SKU-keyed batch update result.
     *
     * Permanent and temporary failures are flattened into a single `failures` array
     * — the wire contract intentionally hides the distinction; clients always see
     * `{sku, error}` regardless of underlying retry semantics.
     *
     * @param BatchUpdateResult<string> $result
     */
    public static function fromBatchUpdateResult(BatchUpdateResult $result): self
    {
        return new self(
            total: $result->total,
            succeeded: $result->succeeded,
            failures: [
                ...self::mapFailures($result->permanentFailures),
                ...self::mapFailures($result->temporaryFailures),
            ],
        );
    }

    /**
     * @param list<array{identifier: string, error: string}> $failures
     *
     * @return list<array{sku: string, error: string}>
     */
    private static function mapFailures(array $failures): array
    {
        return \array_map(
            static fn(array $failure): array => [
                'sku' => $failure['identifier'],
                'error' => $failure['error'],
            ],
            $failures,
        );
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'total' => $this->total,
            'succeeded' => $this->succeeded,
            'failures' => $this->failures,
        ], Response::HTTP_OK);
    }
}
