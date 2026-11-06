<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Domain\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\LoadsConfigUnderEnv;
use Tests\TestCase;

/**
 * An unset or empty APP_ENV fails the boot and `config:cache`; the app never falls back to production (AC10).
 */
final class RequiredAppEnvTest extends TestCase
{
    use LoadsConfigUnderEnv;

    private const string APP_ENV_MESSAGE = 'APP_ENV is not set; set it to local, testing or production';

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingAppEnv(): iterable
    {
        yield 'unset' => [[]];
        yield 'empty' => [['APP_ENV' => '']];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('missingAppEnv')]
    public function app_config_refuses_a_missing_app_env(array $env): void
    {
        try {
            self::loadConfigUnder('app.php', ['APP_ENV'], $env);
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('APP_ENV', $e->configKey);
            $this->assertSame(self::APP_ENV_MESSAGE, $e->getMessage());

            return;
        }

        $this->fail('config/app.php loaded without APP_ENV.');
    }

    #[Test]
    public function app_config_takes_the_environment_from_app_env(): void
    {
        /** @var array{env: mixed} $config */
        $config = self::loadConfigUnder('app.php', ['APP_ENV'], ['APP_ENV' => 'staging']);

        $this->assertSame('staging', $config['env']);
    }

    /**
     * intentional: an empty APP_ENV in the child's real environment shadows the .env value (Dotenv is immutable),
     * so this stays hermetic; an unset one would read .env, so the unset case is covered by the require above.
     */
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function artisanCommands(): iterable
    {
        yield 'boot' => [['--version']];
        yield 'config:cache' => [['config:cache']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('artisanCommands')]
    public function artisan_fails_on_an_empty_app_env_naming_app_env_without_writing_a_cache(array $arguments): void
    {
        $cachePath = \sys_get_temp_dir() . '/cor261-config-cache-' . \bin2hex(\random_bytes(4)) . '.php';
        $process = new Process([\PHP_BINARY, \base_path('artisan'), ...$arguments], \base_path(), [
            'APP_ENV' => '',
            'APP_CONFIG_CACHE' => $cachePath,
        ]);

        try {
            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('In app.php line', $output);
            $this->assertStringContainsString(self::APP_ENV_MESSAGE, $output);
            $this->assertFileDoesNotExist($cachePath);
        } finally {
            if (\is_file($cachePath)) {
                \unlink($cachePath);
            }
        }
    }
}
