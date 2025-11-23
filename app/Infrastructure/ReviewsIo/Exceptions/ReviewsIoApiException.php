<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Exceptions;

use RuntimeException;
use Throwable;

final class ReviewsIoApiException extends RuntimeException
{
    public static function invalidResponse(
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: "Reviews.io API invalid response: {$message}",
            code: 0,
            previous: $previous,
        );
    }
}
