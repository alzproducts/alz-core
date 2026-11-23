<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use Override;
use QuickBooksOnline\API\Core\HttpClients\BaseCurl;

/**
 * Records curl options and replays a canned response instead of opening a connection.
 */
final class SpyCurl extends BaseCurl
{
    /** @var array<int, mixed> */
    public array $options = [];

    public function __construct(
        private readonly string $rawHeaders,
        private readonly string $body,
        private readonly int $status,
    ) {}

    #[Override]
    public function init(): void {}

    #[Override]
    public function isCurlSet(): bool
    {
        return true;
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
        return 0;
    }

    #[Override]
    public function error(): string
    {
        return '';
    }

    #[Override]
    public function getInfo($type): int
    {
        return $type === \CURLINFO_HEADER_SIZE ? \mb_strlen($this->rawHeaders) : $this->status;
    }

    #[Override]
    public function close(): void {}
}
