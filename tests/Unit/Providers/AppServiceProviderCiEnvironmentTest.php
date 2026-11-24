<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AppServiceProviderTest hands the CI variables back as it found them (COR-358).
 */
#[CoversNothing]
final class AppServiceProviderCiEnvironmentTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['CI', 'GITHUB_ACTIONS'] as $name) {
            $this->saved[$name] = \getenv($name);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ciVariablesAndTests(): iterable
    {
        foreach (['CI', 'GITHUB_ACTIONS'] as $name) {
            yield $name . ' through the production helper' => [$name, 'boots_successfully_in_production_with_valid_configuration'];
            yield $name . ' through the test that sets it' => [$name, 'skips_validation_in_ci_environments'];
        }
    }

    #[Test]
    #[DataProvider('ciVariablesAndTests')]
    public function a_variable_set_to_true_before_the_test_is_still_true_after_it(string $name, string $test): void
    {
        \putenv("{$name}=true");

        $this->runInnerTest($test, $name);

        $this->assertSame('true', \getenv($name));
    }

    #[Test]
    #[DataProvider('ciVariablesAndTests')]
    public function a_variable_unset_before_the_test_is_still_unset_after_it(string $name, string $test): void
    {
        \putenv($name);

        $this->runInnerTest($test, $name);

        $this->assertFalse(\getenv($name));
    }

    private function runInnerTest(string $test, string $name): void
    {
        $inner = new AppServiceProviderTest($test);
        $lifecycle = static function (AppServiceProviderTest $case, string $test, string $name): void {
            $case->setUp();
            \putenv("{$name}=true");

            if ($test === 'boots_successfully_in_production_with_valid_configuration') {
                $case->setupProductionEnvironment();
            }

            $case->tearDown();
        };

        Closure::bind($lifecycle, null, AppServiceProviderTest::class)($inner, $test, $name);
    }
}
