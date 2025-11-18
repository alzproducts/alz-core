<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use RuntimeException;

final class ReviewsIoApiException extends RuntimeException
{
    public static function invalidResponse(string $message): self
    {
        return new self("Reviews.io API invalid response: {$message}");
    }
}
