<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Exceptions;

use RuntimeException;
use Throwable;

final class GoogleAdsApiException extends RuntimeException
{
    public static function fromApiError(string $errorCode, string $message, ?Throwable $previous = null): self
    {
        return new self("Google Ads API error [{$errorCode}]: {$message}", 0, $previous);
    }
}
