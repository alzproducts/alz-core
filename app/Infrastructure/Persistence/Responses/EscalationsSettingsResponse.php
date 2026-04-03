<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Responses;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use JsonException;

/**
 * Typed representation of the `settings` JSON column from `config.dashboard`
 * for the HelpScout escalations configuration row.
 *
 * Replaces untyped array decoding with a proper object that maps to Domain.
 */
final readonly class EscalationsSettingsResponse
{
    public function __construct(
        public int $lateThresholdHours,
        public int $latePriorityThresholdHours,
        /** @var list<string> */
        public array $priorityTags,
        /** @var list<string> */
        public array $excludedTags,
        public string $assignedTag,
    ) {}

    /**
     * @throws JsonException When settings JSON is invalid
     */
    public static function fromJson(string $json): self
    {
        /** @var array{lateThresholdHours: int, latePriorityThresholdHours: int, priorityTags: list<string>, excludedTags: list<string>, assignedTag: string} $data */
        $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(...$data);
    }

    public function toDomain(): EscalationsConfig
    {
        return new EscalationsConfig(
            lateThresholdHours: $this->lateThresholdHours,
            latePriorityThresholdHours: $this->latePriorityThresholdHours,
            priorityTags: $this->priorityTags,
            excludedTags: $this->excludedTags,
            assignedTag: $this->assignedTag,
        );
    }
}
