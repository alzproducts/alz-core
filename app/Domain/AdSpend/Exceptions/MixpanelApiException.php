<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class MixpanelApiException extends RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $failedRecords
     */
    public static function fromValidationErrors(array $failedRecords): self
    {
        $count = \count($failedRecords);

        return new self("Mixpanel validation failed for {$count} events");
    }
}
