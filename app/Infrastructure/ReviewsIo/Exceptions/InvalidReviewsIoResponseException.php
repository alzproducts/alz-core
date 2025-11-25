<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Exceptions;

use App\Infrastructure\Exceptions\ApiException;

final class InvalidReviewsIoResponseException extends ApiException
{
    /**
     * Thrown when Reviews.io API response contains null/missing required fields.
     * This is a **runtime validation exception** (always active in production),
     * not an assertion (which compile-out).
     *
     * Distinguishes from ReviewsIoApiException:
     * - ReviewsIoApiException: API returned error status code
     * - InvalidReviewsIoResponseException: API returned 200 but data invalid
     */
}
