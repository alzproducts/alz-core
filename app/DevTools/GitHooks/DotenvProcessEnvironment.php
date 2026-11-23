<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

use Dotenv\Dotenv;

final class DotenvProcessEnvironment
{
    /**
     * Artisan loads `.env` into both `putenv()` and `$_SERVER`, so Symfony Process hands it to every child; unsetting those names stops
     * `.env` values from beating the non-forced `<env>` defaults in phpunit.xml.
     *
     * @return array<string, false>
     */
    public static function withoutDotenvValues(string $envFilePath): array
    {
        $contents = \is_readable($envFilePath) ? \file_get_contents($envFilePath) : false;
        if ($contents === false) {
            return [];
        }

        return \array_map(static fn(): false => false, Dotenv::parse($contents));
    }
}
