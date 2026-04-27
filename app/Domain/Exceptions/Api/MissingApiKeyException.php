<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use App\Domain\Access\Enums\ThirdPartyService;
use Override;

/**
 * Thrown when a required third-party API key has not been configured for the user.
 *
 * Maps to HTTP 412 Precondition Failed — the user must supply an API key before the
 * operation can proceed. Non-retryable: the condition won't resolve automatically.
 */
final class MissingApiKeyException extends PermanentApiFailure
{
    public function __construct(
        public readonly ThirdPartyService $service,
    ) {
        parent::__construct(
            $service->value,
            'No API key configured for this service',
        );
    }

    #[Override]
    public function context(): array
    {
        return [
            ...parent::context(),
            'service' => $this->service->value,
        ];
    }
}
