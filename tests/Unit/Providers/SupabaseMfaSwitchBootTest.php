<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Domain\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * intentional: each case boots a fresh app in a child process, so APP_ENV, CI and SUPABASE_REQUIRE_MFA
 * travel the real env → config → provider path instead of being set on the already-booted test app.
 */
#[CoversNothing]
final class SupabaseMfaSwitchBootTest extends TestCase
{
    private const string BOOT_SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        try {
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo json_encode(['booted' => true, 'env' => $app->environment()]);
        } catch (Throwable $e) {
            echo json_encode([
                'booted' => false,
                'class' => $e::class,
                'configKey' => $e instanceof App\Domain\Exceptions\InvalidConfigurationException ? $e->configKey : null,
            ]);
        }
        PHP;

    /**
     * @return iterable<string, array{string, array<string, string|false>}>
     */
    public static function refusedEnvironments(): iterable
    {
        $ciStates = [
            'CI set' => ['CI' => 'true', 'GITHUB_ACTIONS' => false],
            'GITHUB_ACTIONS set' => ['CI' => false, 'GITHUB_ACTIONS' => 'true'],
            'neither CI variable set' => ['CI' => false, 'GITHUB_ACTIONS' => false],
        ];

        foreach (['production', 'staging', 'development'] as $environment) {
            foreach ($ciStates as $label => $ci) {
                yield "{$environment}, {$label}" => [$environment, $ci];
            }
        }
    }

    /**
     * @param array<string, string|false> $ci
     */
    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function booting_with_mfa_off_outside_local_and_testing_throws(string $environment, array $ci): void
    {
        $result = $this->boot($environment, $ci);

        $this->assertSame(
            ['booted' => false, 'class' => InvalidConfigurationException::class, 'configKey' => 'services.supabase.require_mfa'],
            $result,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'testing' => ['testing'];
    }

    #[Test]
    #[DataProvider('allowedEnvironments')]
    public function booting_with_mfa_off_in_local_or_testing_succeeds(string $environment): void
    {
        $result = $this->boot($environment, ['CI' => false, 'GITHUB_ACTIONS' => false]);

        $this->assertSame(['booted' => true, 'env' => $environment], $result);
    }

    /**
     * @param array<string, string|false> $ci
     */
    private function boot(string $environment, array $ci): mixed
    {
        $process = new Process(
            [\PHP_BINARY, '-r', self::BOOT_SCRIPT],
            \base_path(),
            ['APP_ENV' => $environment, 'SUPABASE_REQUIRE_MFA' => 'false', ...$ci],
        );
        $process->mustRun();

        return \json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
