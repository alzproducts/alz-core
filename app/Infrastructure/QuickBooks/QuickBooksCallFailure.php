<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

/**
 * What a failed-call log entry may say about the call. Never the SDK message or the query text: both can hold customer data.
 */
final readonly class QuickBooksCallFailure
{
    public function __construct(
        public QuickBooksCall $call,
        public ?string $intuitTid,
        public int $curlErrno = 0,
    ) {}

    public static function of(QuickBooksCall $call, QuickBooksCurlHttpClient $httpClient): self
    {
        return new self($call, $httpClient->intuitTid(), $httpClient->curlErrno());
    }

    /**
     * @return array{call: string, intuit_tid: ?string, curl_errno?: int}
     */
    public function context(): array
    {
        $context = ['call' => $this->call->value, 'intuit_tid' => $this->intuitTid];
        if ($this->curlErrno !== 0) {
            $context['curl_errno'] = $this->curlErrno;
        }

        return $context;
    }
}
