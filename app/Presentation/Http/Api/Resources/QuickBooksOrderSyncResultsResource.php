<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin OrderSyncResultsResult
 */
final class QuickBooksOrderSyncResultsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var OrderSyncResultsResult $syncResults */
        $syncResults = $this->resource;

        return [
            'total' => \count($syncResults->results),
            'counts' => $syncResults->counts(),
            'results' => \array_map(static fn(OrderSyncResultDTO $result): array => self::result($result), $syncResults->results),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(OrderSyncResultDTO $result): array
    {
        return [
            'reference' => $result->reference,
            'outcome' => $result->outcome->value,
            'doc_type' => $result->docType?->value,
            'qbo_doc_number' => $result->qboDocNumber,
            'qbo_txn_id' => $result->qboTxnId,
            'warnings' => [],
            'reason' => self::reason($result->reason, $result->message),
        ];
    }

    /**
     * @return array{code: string, message: string|null, retryable: bool}|null
     */
    private static function reason(?SyncReasonCode $reason, ?string $message): ?array
    {
        return $reason === null ? null : [
            'code' => $reason->value,
            'message' => $message,
            'retryable' => $reason->retryable(),
        ];
    }
}
