<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The production-like compose starts the app only once the baseline carries Realtime's tenant schema.
 */
#[CoversNothing]
#[Group('repo-static')]
final class BaselineComposeTest extends TestCase
{
    #[Test]
    public function the_app_waits_for_the_realtime_one_shot_to_complete(): void
    {
        /** @var array{services: array{app: array{depends_on: array<string, array{condition?: string}>}}} $compose */
        $compose = self::parse('docker-compose.prod.yml');

        $this->assertSame('service_completed_successfully', $compose['services']['app']['depends_on']['realtime-migrate']['condition'] ?? null);
    }

    #[Test]
    public function the_included_baseline_defines_the_realtime_one_shot(): void
    {
        /** @var array{services: array<string, mixed>} $baseline */
        $baseline = self::parse('database/baseline/compose.yaml');

        $this->assertArrayHasKey('realtime-migrate', $baseline['services']);
    }

    /**
     * @return array<mixed>
     */
    private static function parse(string $path): array
    {
        /** @var array<mixed> */
        return Yaml::parse(TrackedFiles::reader(\dirname(__DIR__, 3))($path));
    }
}
