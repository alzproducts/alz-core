<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * Every CI setup-php step loads bcmath and `composer.json` requires `ext-bcmath` (COR-295 AC6, pitfall P2).
 */
final class CiPhpExtensionsCheck
{
    public const string WORKFLOW = '.github/workflows/ci.yml';

    public const string COMPOSER_JSON = 'composer.json';

    public const int EXPECTED_SETUP_PHP_STEPS = 6;

    private const string SETUP_PHP = 'shivammathur/setup-php';

    /**
     * @param array<mixed> $workflow parsed workflow YAML
     * @param array<mixed> $composer parsed composer.json
     *
     * @return list<string>
     */
    public static function violations(array $workflow, array $composer): array
    {
        $violations = [];
        $steps = self::setupPhpSteps($workflow);

        if (\count($steps) !== self::EXPECTED_SETUP_PHP_STEPS) {
            $violations[] = \sprintf('expected %d setup-php steps, found %d', self::EXPECTED_SETUP_PHP_STEPS, \count($steps));
        }

        foreach ($steps as $job => $extensions) {
            if (!self::loadsBcmath($extensions)) {
                $violations[] = 'setup-php step does not load bcmath: ' . $job;
            }
        }

        $require = $composer['require'] ?? null;

        if (!\is_array($require) || !\array_key_exists('ext-bcmath', $require)) {
            $violations[] = 'composer.json does not require ext-bcmath';
        }

        return $violations;
    }

    /**
     * @param array<mixed> $workflow
     *
     * @return array<string, string> `<job>#<step index>` => the step's `extensions` value ('' when absent)
     */
    private static function setupPhpSteps(array $workflow): array
    {
        $found = [];
        $jobs = $workflow['jobs'] ?? [];

        foreach (\is_array($jobs) ? $jobs : [] as $name => $job) {
            $steps = \is_array($job) ? ($job['steps'] ?? []) : [];

            foreach (\is_array($steps) ? $steps : [] as $index => $step) {
                if (!\is_array($step) || !\is_string($step['uses'] ?? null) || !\str_starts_with($step['uses'], self::SETUP_PHP . '@')) {
                    continue;
                }

                $with = \is_array($step['with'] ?? null) ? $step['with'] : [];
                $extensions = $with['extensions'] ?? '';
                $found[$name . '#' . $index] = \is_string($extensions) ? $extensions : '';
            }
        }

        return $found;
    }

    /**
     * setup-php reads the list left to right: `none` drops every shared extension, `:ext` disables one.
     */
    private static function loadsBcmath(string $extensions): bool
    {
        $loaded = false;

        foreach (\explode(',', $extensions) as $token) {
            $token = \mb_strtolower(\mb_trim($token));

            if ($token === 'bcmath') {
                $loaded = true;
            } elseif ($token === 'none' || $token === ':bcmath') {
                $loaded = false;
            }
        }

        return $loaded;
    }
}
