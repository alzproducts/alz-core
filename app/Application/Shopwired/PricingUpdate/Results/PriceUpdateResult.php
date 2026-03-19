<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

/**
 * Outcome of a product-scoped price update operation.
 *
 * Tracks per-SKU outcomes across all three categories:
 * - Skipped: pre-flight determined no change needed
 * - Succeeded: API confirmed the update
 * - Failed: validation rejected or API failed (permanent or temporary)
 */
final readonly class PriceUpdateResult
{
    /**
     * @param int $total Total SKUs submitted
     * @param int $succeeded API confirmed updated: true
     * @param list<SkippedPriceUpdateResult> $skipped Pre-flight: prices unchanged
     * @param list<FailedPriceUpdateResult> $permanentFailures Validation rejected or API updated: false
     * @param list<FailedPriceUpdateResult> $temporaryFailures TransientApiFailure from API
     */
    public function __construct(
        public int $total,
        public int $succeeded,
        public array $skipped = [],
        public array $permanentFailures = [],
        public array $temporaryFailures = [],
    ) {}

    /**
     * Build result from pre-flight and optional API phases.
     *
     * When nothing passes pre-flight, $apiResult is null (no API call made).
     */
    public static function fromPhases(
        int $total,
        PreFlightValidationResult $preFlight,
        ?BatchApiResult $apiResult,
    ): self {
        if ($apiResult === null) {
            return new self(
                total: $total,
                succeeded: 0,
                skipped: $preFlight->skipped,
                permanentFailures: $preFlight->permanentFailures,
            );
        }

        return new self(
            total: $total,
            succeeded: $apiResult->succeeded,
            skipped: $preFlight->skipped,
            permanentFailures: [
                ...$preFlight->permanentFailures,
                ...$apiResult->permanentFailures,
            ],
            temporaryFailures: $apiResult->temporaryFailures,
        );
    }

    public function hasFailures(): bool
    {
        return $this->permanentFailures !== [] || $this->temporaryFailures !== [];
    }

    public function allSucceeded(): bool
    {
        return ! $this->hasFailures() && $this->succeeded === $this->total;
    }

    public function isPartialSuccess(): bool
    {
        return $this->succeeded > 0 && $this->hasFailures();
    }
}
