<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use QuickBooksOnline\API\DataService\DataService;

/**
 * One SDK call attempt: a fresh DataService and the hardened client it sends through,
 * kept together so the caller can read the attempt's Retry-After.
 */
final readonly class QuickBooksDataServiceSession
{
    public function __construct(
        public DataService $dataService,
        public QuickBooksCurlHttpClient $httpClient,
    ) {}
}
