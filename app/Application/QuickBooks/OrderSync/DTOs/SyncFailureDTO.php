<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;

/**
 * Why an order failed: `code` is what staff see, `loggedCode` what this attempt hit. `message` can hold
 * customer data, so it must stay out of logs.
 */
final readonly class SyncFailureDTO
{
    public function __construct(
        public SyncReasonCode $code,
        public SyncReasonCode $loggedCode,
        public string $message,
        public ?QuickBooksFault $fault,
    ) {}

    public static function of(SyncReasonCode $code, ?string $message = null, ?QuickBooksFault $fault = null): self
    {
        return new self($code, $code, $message ?? $code->message(), $fault);
    }
}
