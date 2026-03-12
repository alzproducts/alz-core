<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AbstractApiException;
use Illuminate\Http\Client\Response;

/**
 * Result of a concurrent pool POST operation.
 *
 * Contains successful responses keyed by batch name, plus the first
 * transport failure encountered (if any). Allows callers to process
 * partial successes before propagating the failure.
 */
final readonly class PoolPostResult
{
    /**
     * @param array<string, Response> $responses Successfully completed pool responses
     * @param ?AbstractApiException $transportFailure First translated transport failure (null = all succeeded)
     */
    public function __construct(
        public array $responses,
        public ?AbstractApiException $transportFailure = null,
    ) {}

}
