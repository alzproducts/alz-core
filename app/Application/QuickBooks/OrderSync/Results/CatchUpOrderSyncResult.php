<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

/**
 * A skipped or refused run wrote nothing and queued nothing.
 */
final readonly class CatchUpOrderSyncResult
{
    public const string SKIPPED_SWITCHED_OFF = 'switched_off';

    public const string SKIPPED_PREVIOUS_RUN_OPEN = 'previous_run_open';

    public const string SKIPPED_NO_CANDIDATES = 'no_candidates';

    public const string REFUSED_NO_CONNECTION = 'no_connection';

    public const string REFUSED_MAPPINGS_UNRESOLVED = 'mappings_unresolved';

    public const string REFUSED_TOKEN_REJECTED = 'token_rejected';

    public const string REFUSED_FLOOR_UNSET = 'floor_unset';

    public const string REFUSED_FLOOR_MALFORMED = 'floor_malformed';

    public const string REFUSED_SCHEDULE_FROM_MALFORMED = 'schedule_from_malformed';

    private function __construct(
        public ?string $runId,
        public int $candidateCount,
        public int $chunkCount,
        public ?string $skipReason,
        public ?string $refuseReason,
    ) {}

    public static function queued(string $runId, int $candidateCount, int $chunkCount): self
    {
        return new self($runId, $candidateCount, $chunkCount, null, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(null, 0, 0, $reason, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, 0, 0, null, $reason);
    }
}
