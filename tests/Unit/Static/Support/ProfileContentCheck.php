<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * AC11 over the repo listing: both committed profile files must be listed, and each must pass its content check.
 */
final class ProfileContentCheck
{
    public const string PROFILE_JSON = 'database/seed-profile/products-profile.json';

    public const string EXPORT_SQL = 'database/seed-profile/products-profile-export.sql';

    /**
     * @param list<string> $paths repo-relative paths
     * @param callable(string): string $read
     *
     * @return list<string>
     */
    public static function violations(array $paths, callable $read): array
    {
        $violations = [];

        foreach ([self::PROFILE_JSON => ProfileJsonCheck::violations(...), self::EXPORT_SQL => ProfileSqlCheck::violations(...)] as $file => $check) {
            if (!\in_array($file, $paths, true)) {
                $violations[] = 'profile file missing from the listing: ' . $file;

                continue;
            }

            \array_push($violations, ...\array_map(static fn(string $violation): string => $file . ': ' . $violation, $check($read($file))));
        }

        return $violations;
    }
}
