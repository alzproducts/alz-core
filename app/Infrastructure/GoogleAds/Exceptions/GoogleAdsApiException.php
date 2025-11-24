<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Exceptions;

use App\Infrastructure\Exceptions\ApiException;
use Throwable;

final class GoogleAdsApiException extends ApiException
{
    public static function fromApiError(string $errorCode, string $message, ?Throwable $previous = null): self
    {
        return new self("Google Ads API error [{$errorCode}]: {$message}", 0, $previous);
    }
}
