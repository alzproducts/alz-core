<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use Webmozart\Assert\Assert;

/**
 * One attempt at a chunk. Waiting for a slot keeps the attempt; only unfinished work raises it.
 */
final readonly class OrderChunkRunDTO
{
    public const int MAX_ATTEMPTS = 6;

    public function __construct(
        public OrderSyncChunkDTO $chunk,
        public int $attempt = 1,
    ) {
        Assert::range($attempt, 1, self::MAX_ATTEMPTS, 'A chunk is attempted a limited number of times');
    }

    public function isLastAttempt(): bool
    {
        return $this->attempt === self::MAX_ATTEMPTS;
    }

    /**
     * A re-attempt never claims a row holding `outcome_unknown`, forced or not: an earlier attempt may have created its document.
     */
    public function claimRules(): OrderSyncClaimRulesDTO
    {
        return new OrderSyncClaimRulesDTO($this->chunk->forceRetry, $this->attempt > 1);
    }

    /**
     * @param non-empty-list<int> $references the ones still unsent
     */
    public function reattempt(array $references): self
    {
        $chunk = new OrderSyncChunkDTO(
            $this->chunk->realmId,
            $references,
            $this->chunk->forceRetry,
            $this->chunk->trigger,
            $this->chunk->runId,
        );

        return new self($chunk, $this->attempt + 1);
    }
}
