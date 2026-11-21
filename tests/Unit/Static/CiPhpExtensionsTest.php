<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\Static\Support\CiPhpExtensionsCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * Every CI setup-php step loads bcmath, so PHPArkitect sees `BcMath\Number` as a built-in in every job (COR-295 AC6).
 */
#[CoversNothing]
#[Group('repo-static')]
final class CiPhpExtensionsTest extends TestCase
{
    #[Test]
    public function every_setup_php_step_loads_bcmath_and_composer_requires_it(): void
    {
        $this->assertSame([], CiPhpExtensionsCheck::violations(self::workflow(), self::composer()));
    }

    #[Test]
    public function the_workflow_has_the_expected_setup_php_steps(): void
    {
        $this->assertSame(
            ['expected 6 setup-php steps, found 0', 'composer.json does not require ext-bcmath'],
            CiPhpExtensionsCheck::violations([], []),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function plantedExtensions(): iterable
    {
        yield 'bcmath removed from code-style' => ['code-style', 'mbstring, xml, ctype, json, soap', 'code-style#1'];
        yield 'bcmath removed from security' => ['security', '', 'security#1'];
        yield 'bcmath disabled after being listed' => ['tests', 'dom, bcmath, :bcmath, redis', 'tests#1'];
        yield 'none with no later bcmath' => ['mutation-domain', 'bcmath, none, dom', 'mutation-domain#1'];
    }

    #[Test]
    #[DataProvider('plantedExtensions')]
    public function a_step_without_bcmath_fails_the_check(string $job, string $extensions, string $expected): void
    {
        $this->assertSame(
            ['setup-php step does not load bcmath: ' . $expected],
            CiPhpExtensionsCheck::violations(self::withExtensions(self::workflow(), $job, $extensions), self::composer()),
        );
    }

    #[Test]
    public function none_followed_by_bcmath_passes(): void
    {
        $this->assertSame([], CiPhpExtensionsCheck::violations(self::withExtensions(self::workflow(), 'security', 'none, bcmath'), self::composer()));
    }

    #[Test]
    public function composer_without_ext_bcmath_fails_the_check(): void
    {
        $composer = self::composer();
        /** @var array<string, string> $require */
        $require = $composer['require'];
        unset($require['ext-bcmath']);
        $composer['require'] = $require;

        $this->assertSame(['composer.json does not require ext-bcmath'], CiPhpExtensionsCheck::violations(self::workflow(), $composer));
    }

    /**
     * @return array<mixed>
     */
    private static function workflow(): array
    {
        /** @var array<mixed> */
        return Yaml::parse(self::committedFile(CiPhpExtensionsCheck::WORKFLOW));
    }

    /**
     * @return array<mixed>
     */
    private static function composer(): array
    {
        /** @var array<mixed> */
        return \json_decode(self::committedFile(CiPhpExtensionsCheck::COMPOSER_JSON), true, 32, \JSON_THROW_ON_ERROR);
    }

    private static function committedFile(string $path): string
    {
        return TrackedFiles::reader(\dirname(__DIR__, 3))($path);
    }

    /**
     * Sets the `extensions` of the job's setup-php step (step index 1 in every job).
     *
     * @param array<mixed> $workflow
     *
     * @return array<mixed>
     */
    private static function withExtensions(array $workflow, string $job, string $extensions): array
    {
        /** @var array{jobs: array<string, array{steps: array<int, array{uses?: string, with: array<string, string>}>}>} $workflow */
        self::assertSame('shivammathur/setup-php@v2', $workflow['jobs'][$job]['steps'][1]['uses'] ?? null);
        $workflow['jobs'][$job]['steps'][1]['with']['extensions'] = $extensions;

        return $workflow;
    }
}
