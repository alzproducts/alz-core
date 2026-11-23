<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Serves fixed content for php://stdin while a callback runs. Every other php:// stream is unavailable meanwhile.
 */
final class FakeStdin
{
    /** @var resource|null */
    public $context;

    private static string $content = '';

    private int $position = 0;

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function during(string $content, callable $callback): mixed
    {
        self::$content = $content;
        \stream_wrapper_unregister('php');
        \stream_wrapper_register('php', self::class);

        try {
            return $callback();
        } finally {
            \stream_wrapper_restore('php');
        }
    }

    public function stream_open(string $path): bool
    {
        return $path === 'php://stdin';
    }

    public function stream_read(int $count): string
    {
        $chunk = \mb_substr(self::$content, $this->position, $count);
        $this->position += \mb_strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \mb_strlen(self::$content);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
