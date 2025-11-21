<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class GoogleAdsApiException extends RuntimeException
{
    public static function fromApiError(string $errorCode, string $message): self
    {
        return new self("Google Ads API error [{$errorCode}]: {$message}");
    }
}
