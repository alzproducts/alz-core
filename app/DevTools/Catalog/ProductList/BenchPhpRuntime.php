<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use RuntimeException;

/**
 * Keeps xdebug and pcov out of every timed process. The CLI ini enables both, and `php -d` flags given to
 * `octane:start` never reach the Swoole server it spawns, so the switch travels in the environment instead,
 * which the server and its workers inherit.
 */
final readonly class BenchPhpRuntime
{
    private const string INI = "xdebug.mode=off\npcov.enabled=0\n";

    public function __construct(
        private Filesystem $files,
        private ProcessFactory $process,
    ) {}

    public function isInstrumented(): bool
    {
        $xdebugActive = \function_exists('xdebug_info') && \xdebug_info('mode') !== [];

        return $xdebugActive || \filter_var(\ini_get('pcov.enabled'), \FILTER_VALIDATE_BOOL);
    }

    public function wasRelaunched(): bool
    {
        return \getenv('XDEBUG_MODE') === 'off' && \str_contains((string) \getenv('PHP_INI_SCAN_DIR'), $this->iniDirectory());
    }

    /**
     * @return array<string, string>
     */
    public function uninstrumentedEnvironment(): array
    {
        $this->files->ensureDirectoryExists($this->iniDirectory());
        $this->files->put($this->iniDirectory() . '/zz-bench-no-instrumentation.ini', self::INI);

        $scanDirectory = (string) \getenv('PHP_INI_SCAN_DIR');

        // intentional: a leading separator keeps PHP's default scan directory and appends ours after it.
        return ['XDEBUG_MODE' => 'off', 'PHP_INI_SCAN_DIR' => $scanDirectory . \PATH_SEPARATOR . $this->iniDirectory()];
    }

    /**
     * Re-runs this artisan command with xdebug and pcov off, streaming its output.
     *
     * @param list<string> $arguments Command name and options
     * @param callable(string, string): void $output
     *
     * @return int The relaunched command's exit code
     *
     * @throws RuntimeException When the process cannot be started
     */
    public function relaunch(array $arguments, callable $output): int
    {
        return $this->process
            ->newPendingProcess()
            ->forever()
            ->path(\base_path())
            ->env($this->uninstrumentedEnvironment())
            ->run([\PHP_BINARY, 'artisan', ...$arguments], $output)
            ->exitCode() ?? 1;
    }

    private function iniDirectory(): string
    {
        return \base_path('tmp/cor-258/php-ini');
    }
}
