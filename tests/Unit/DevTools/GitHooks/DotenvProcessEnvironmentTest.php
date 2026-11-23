<?php

declare(strict_types=1);

namespace Tests\Unit\DevTools\GitHooks;

use App\DevTools\GitHooks\DotenvProcessEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(DotenvProcessEnvironment::class)]
final class DotenvProcessEnvironmentTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        $this->envFile = \sys_get_temp_dir() . '/dotenv-process-env-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (\is_file($this->envFile)) {
            \unlink($this->envFile);
        }
        \putenv('DOTENV_PROBE_NAME');
        unset($_SERVER['DOTENV_PROBE_NAME']);
    }

    public function test_it_marks_every_dotenv_name_for_unsetting(): void
    {
        \file_put_contents($this->envFile, "FIRST=1\n# comment\nSECOND=\"two words\"\n");

        self::assertSame(
            ['FIRST' => false, 'SECOND' => false],
            DotenvProcessEnvironment::withoutDotenvValues($this->envFile),
        );
    }

    public function test_a_missing_file_unsets_nothing(): void
    {
        self::assertSame([], DotenvProcessEnvironment::withoutDotenvValues($this->envFile));
    }

    public function test_a_dotenv_value_in_the_process_environment_does_not_reach_the_child_process(): void
    {
        \file_put_contents($this->envFile, "DOTENV_PROBE_NAME=from-dotenv\n");
        \putenv('DOTENV_PROBE_NAME=from-dotenv');
        $_SERVER['DOTENV_PROBE_NAME'] = 'from-dotenv';
        $command = [PHP_BINARY, '-r', 'echo (string) getenv("DOTENV_PROBE_NAME");'];

        $inherited = new Process($command);
        $inherited->run();
        $filtered = new Process($command, null, DotenvProcessEnvironment::withoutDotenvValues($this->envFile));
        $filtered->run();

        self::assertSame('from-dotenv', $inherited->getOutput(), 'control: Process inherits a value held in both getenv() and $_SERVER, as Laravel loads .env');
        self::assertSame('', $filtered->getOutput());
    }
}
