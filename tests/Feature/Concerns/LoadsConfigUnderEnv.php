<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

/**
 * Requires a config file fresh under a given process environment, restoring every touched variable afterwards.
 */
trait LoadsConfigUnderEnv
{
    /**
     * @param list<string> $keys every variable to control; one missing from $env is unset
     * @param array<string, string> $env
     */
    private static function loadConfigUnder(string $file, array $keys, array $env): mixed
    {
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, \getenv($key)];
            self::setEnv($key, $env[$key] ?? null);
        }

        try {
            return require \config_path($file);
        } finally {
            foreach ($saved as $key => [$server, $envValue, $putenv]) {
                self::restoreEnv($key, $server, $envValue, $putenv);
            }
        }
    }

    private static function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            \putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        \putenv("{$key}={$value}");
    }

    private static function restoreEnv(string $key, mixed $server, mixed $envValue, string|false $putenv): void
    {
        unset($_SERVER[$key], $_ENV[$key]);

        if ($server !== null) {
            $_SERVER[$key] = $server;
        }

        if ($envValue !== null) {
            $_ENV[$key] = $envValue;
        }

        \putenv($putenv === false ? $key : "{$key}={$putenv}");
    }
}
