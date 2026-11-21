<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The jq gate the user runs on the prod replay output before anyone reads it (AC7, D9).
 */
#[CoversNothing]
#[Group('repo-static')]
final class VatReplayOutputCheckTest extends TestCase
{
    private const string FILTER = 'database/reports/vat-replay-output-check.jq';

    private const string VALID = 'tests/Fixtures/vat-replay/valid.json';

    #[Test]
    public function the_valid_fixture_passes(): void
    {
        $this->assertSame(0, self::check(self::valid()));
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function plantedOutputs(): iterable
    {
        yield 'an unknown top-level key' => [static fn(array $o): array => self::set($o, ['orders_by_reference'], [])];
        yield 'an unknown excluded reason' => [static fn(array $o): array => self::set($o, ['excluded', 'refunded'], 1)];
        yield 'an unknown variant mode' => [static fn(array $o): array => self::set($o, ['m1', 'per_order'], [])];
        yield 'an unknown mode key' => [static fn(array $o): array => self::set($o, ['m1', 'per_line', 'net_total'], 1)];
        yield 'fallback keys under m1' => [static fn(array $o): array => self::set($o, ['m1', 'per_rate', 'fallback_lines'], 0)];
        yield 'a missing fallback key' => [static function (array $o): array {
            /** @var array<string, array<string, array<string, mixed>>> $zap */
            $zap = $o['zap_as_built'];
            unset($zap['per_rate']['fallback_orders']);
            $o['zap_as_built'] = $zap;

            return $o;
        }];
        yield 'a string count' => [static fn(array $o): array => self::set($o, ['m1', 'per_line', 'orders'], '5')];
        yield 'a float count' => [static fn(array $o): array => self::set($o, ['zap_as_built', 'per_line', 'mismatched'], 1.5)];
        yield 'a negative count' => [static fn(array $o): array => self::set($o, ['excluded', 'null_tax_value'], -1)];
        yield 'a non-integer delta key' => [static fn(array $o): array => self::set($o, ['m1', 'per_line', 'delta_pence', 'abc'], 1)];
        yield 'an email as a shape key' => [static fn(array $o): array => self::set($o, ['m1', 'per_rate', 'shapes', 'someone@example.test'], 1)];
        yield 'a malformed hash' => [static fn(array $o): array => self::set($o, ['sql_sha256'], 'abc')];
        yield 'a non-ISO timestamp' => [static fn(array $o): array => self::set($o, ['exported_at'], '21/11/2026')];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $plant
     */
    #[Test]
    #[DataProvider('plantedOutputs')]
    public function a_planted_output_fails_the_check(callable $plant): void
    {
        $this->assertSame(1, self::check($plant(self::valid())));
    }

    /**
     * @param array<string, mixed> $output
     */
    private static function check(array $output): ?int
    {
        $json = \json_encode($output, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_FORCE_OBJECT);
        $process = new Process(['jq', '-e', '-f', self::FILTER], \dirname(__DIR__, 3), null, $json);
        $process->run();

        return $process->getExitCode();
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        $contents = \file_get_contents(\dirname(__DIR__, 3) . '/' . self::VALID);
        self::assertIsString($contents);

        /** @var array<string, mixed> */
        return \json_decode($contents, true, 16, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $output
     * @param non-empty-list<string> $path
     *
     * @return array<string, mixed>
     */
    private static function set(array $output, array $path, mixed $value): array
    {
        $key = \array_shift($path);

        if ($path === []) {
            $output[$key] = $value;

            return $output;
        }

        /** @var array<string, mixed> $child */
        $child = $output[$key];
        $output[$key] = self::set($child, $path, $value);

        return $output;
    }
}
