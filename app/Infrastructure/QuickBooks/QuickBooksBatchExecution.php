<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use QuickBooksOnline\API\Data\IPPIntuitEntity;

/**
 * One batch call's result: either QBO's per-item responses keyed by bId, or one outcome every item shares because the
 * call itself failed (a thrown send, a call-level Fault, or a 429 past the retry window).
 */
final readonly class QuickBooksBatchExecution
{
    /**
     * @param array<array-key, mixed> $responses
     */
    private function __construct(
        private array $responses,
        private ?BatchItemOutcomeResult $everyItem,
        public ?string $intuitTid,
    ) {}

    /**
     * @param array<array-key, mixed> $responses the SDK's batch item responses, keyed by bId
     */
    public static function responses(array $responses, ?string $intuitTid = null): self
    {
        return new self($responses, null, $intuitTid);
    }

    public static function everyItem(BatchItemOutcomeResult $outcome, ?string $intuitTid = null): self
    {
        return new self([], $outcome, $intuitTid);
    }

    /**
     * @param class-string<IPPIntuitEntity> $expected the entity class the item was sent as
     */
    public function outcomeFor(string $batchItemId, string $expected): BatchItemOutcomeResult
    {
        return $this->everyItem ?? QuickBooksFaultMapper::batchItem($this->responses[$batchItemId] ?? null, $expected);
    }
}
