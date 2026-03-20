<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\Api\AbstractApiException;
use Illuminate\Http\Client\Response;

/**
 * Result of a concurrent pool POST operation.
 *
 * Contains successful responses keyed by batch name, plus all
 * transport failures encountered. Allows callers to process
 * partial successes and inspect every failure individually.
 */
final readonly class PoolPostResult
{
    /**
     * @param array<string, Response> $responses Successfully completed pool responses
     * @param list<AbstractApiException> $transportFailures All translated transport failures (empty = all succeeded)
     */
    public function __construct(
        public array $responses,
        public array $transportFailures = [],
    ) {}
}
