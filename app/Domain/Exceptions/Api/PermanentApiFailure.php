<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

/**
 * Non-retryable API failure.
 *
 * Concrete exceptions extending this class represent permanent conditions
 * that will not resolve by retrying (auth failures, validation errors,
 * missing resources, unexpected results).
 *
 * Jobs should catch this to fail immediately without retry.
 *
 * @see TransientApiFailure For retryable failures
 */
abstract class PermanentApiFailure extends AbstractApiException {}
