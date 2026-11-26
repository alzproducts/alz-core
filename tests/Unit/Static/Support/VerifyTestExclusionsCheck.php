<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * The groups CI's test step excludes equal the `verify test` verb's default exclusions, `repo-static` aside (COR-284 AC7, pitfall P1).
 */
final class VerifyTestExclusionsCheck
{
    public const string WORKFLOW = '.github/workflows/ci.yml';

    public const string VERB = '.agents/skills/verify/verify';

    private const string TEST_STEP = 'Execute test suite';

    /**
     * @return list<string>
     */
    public static function violations(string $workflowYaml, string $verbScript): array
    {
        $ci = self::ciGroups($workflowYaml);
        $verb = self::verbGroups($verbScript);

        if ($ci === null || $verb === null) {
            return [
                ...($ci === null ? ['CI test step not found exactly once'] : []),
                ...($verb === null ? ['verb TEST_DEFAULT_EXCLUDE_GROUPS not found'] : []),
            ];
        }

        return [
            ...\array_map(static fn(string $group): string => 'group only in CI: ' . $group, \array_values(\array_diff($ci, $verb))),
            ...\array_map(static fn(string $group): string => 'group only in the verb: ' . $group, \array_values(\array_diff($verb, $ci))),
        ];
    }

    /**
     * @return list<string>|null sorted groups, null when the step is missing or ambiguous
     */
    private static function ciGroups(string $workflowYaml): ?array
    {
        /** @var array{jobs?: array<string, array{steps?: list<array{name?: string, run?: string}>}>} $workflow */
        $workflow = Yaml::parse($workflowYaml);
        $runs = [];

        foreach ($workflow['jobs'] ?? [] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (($step['name'] ?? null) === self::TEST_STEP && isset($step['run'])) {
                    $runs[] = $step['run'];
                }
            }
        }

        if (\count($runs) !== 1) {
            return null;
        }

        \preg_match_all('/--exclude-group[= ]([\w-]+)/', $runs[0], $matches);

        return self::normalise($matches[1]);
    }

    /**
     * @return list<string>|null
     */
    private static function verbGroups(string $verbScript): ?array
    {
        if (\preg_match("/^TEST_DEFAULT_EXCLUDE_GROUPS='([^']*)'/m", $verbScript, $match) !== 1) {
            return null;
        }

        return self::normalise(\preg_split('/\s+/', \mb_trim($match[1])) ?: []);
    }

    /**
     * @param array<array-key, string> $groups
     *
     * @return list<string>
     */
    private static function normalise(array $groups): array
    {
        $groups = \array_values(\array_unique(\array_filter($groups, static fn(string $group): bool => $group !== '' && $group !== 'repo-static')));
        \sort($groups);

        return $groups;
    }
}
