<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use Override;
use QuickBooksOnline\API\Core\HttpClients\BaseCurl;
use QuickBooksOnline\API\Exception\SdkException;

/**
 * Records curl options and replays a canned response instead of opening a connection. Like `BaseCurl`, it reads the
 * error number only while the handle is open, and `close()` closes it.
 */
final class SpyCurl extends BaseCurl
{
    /** @var array<int, mixed> */
    public array $options = [];

    private bool $open = false;

    public function __construct(
        private readonly string $rawHeaders,
        private readonly string $body,
        private readonly int $status,
        private readonly int $errno = 0,
    ) {}

    #[Override]
    public function init(): void
    {
        $this->open = true;
    }

    #[Override]
    public function isCurlSet(): bool
    {
        return $this->open;
    }

    /**
     * @param array<int, mixed> $ary
     */
    #[Override]
    public function setupCurlOptArray(array $ary): void
    {
        $this->options = $ary;
    }

    #[Override]
    public function execute(): string
    {
        return $this->rawHeaders . $this->body;
    }

    #[Override]
    public function errno(): int
    {
        if (!$this->open) {
            throw new SdkException('cURL instance is not set when trying to get errno code from the execution.');
        }

        return $this->errno;
    }

    #[Override]
    public function error(): string
    {
        return $this->errno === 0 ? '' : 'Operation timed out';
    }

    #[Override]
    public function getInfo($type): int
    {
        return $type === \CURLINFO_HEADER_SIZE ? \mb_strlen($this->rawHeaders) : $this->status;
    }

    #[Override]
    public function close(): void
    {
        $this->open = false;
    }
}
