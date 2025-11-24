<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Exceptions;

use App\Infrastructure\Exceptions\ApiException;

final class MixpanelApiException extends ApiException
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
