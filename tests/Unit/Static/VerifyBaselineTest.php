<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Static\Support\VerifyStubRun;

/**
 * `verify baseline` boots one base run beside the head run, or exits with the reason and leaves nothing behind.
 */
#[CoversNothing]
#[Group('repo-static')]
final class VerifyBaselineTest extends TestCase
{
    private VerifyStubRun $run;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->run = new VerifyStubRun();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->run->remove();

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_a_ref_that_cannot_be_a_base(): void
    {
        $this->run->commit();
        $this->run->git('checkout', '-q', '-b', 'side');
        $side = $this->run->commit(['side' => 'x']);
        $this->run->git('checkout', '-q', 'develop');
        $head = $this->run->commit(['change' => 'x']);
        $this->run->headUp();

        $rows = [
            [$head, 'is HEAD itself'],
            [$side, 'is not an ancestor of HEAD'],
            ['no-such-ref', 'resolves to no commit'],
        ];

        foreach ($rows as [$ref, $reason]) {
            $this->assertRefused($this->run->verify('baseline', '--ref', $ref), 64, "--ref {$reason}");
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function seedInputs(): iterable
    {
        yield 'seeders' => ['database/seeders/Seeder.php', 'two', 'database/seeders'];
        yield 'factories' => ['database/factories/Factory.php', 'two', 'database/factories'];
        yield 'seed profile' => ['database/seed-profile/profile.json', 'two', 'database/seed-profile'];
        yield 'seed command' => ['app/Presentation/Console/Commands/Dev/SeedFakeDataCommand.php', 'two', 'SeedFakeDataCommand.php'];
        yield 'locked Faker' => ['composer.lock', '{"packages": [{"name": "fakerphp/faker", "version": "2.0"}], "packages-dev": []}', 'fakerphp/faker version in composer.lock (1.0 at the base, 2.0 at HEAD)'];
    }

    #[Test]
    #[DataProvider('seedInputs')]
    public function it_refuses_commits_that_seed_differently(string $path, string $contents, string $named): void
    {
        $base = $this->run->commit();
        $this->run->commit([$path => $contents]);
        $this->run->headUp();

        $baseline = $this->run->verify('baseline', '--ref', $base);

        $this->assertRefused($baseline, 75, 'the two commits seed differently');
        $this->assertStringContainsString($named, $baseline->getErrorOutput());
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function bootGaps(): iterable
    {
        yield 'no Dockerfile.verify' => [['Dockerfile.verify' => null], 'no Dockerfile.verify'];
        yield 'no db:ping' => [['docker-entrypoint.sh' => "php artisan db:show\n"], 'no db:ping in docker-entrypoint.sh'];
        yield 'no realtime-migrate.sh' => [['database/baseline/realtime-migrate.sh' => null], 'no database/baseline/realtime-migrate.sh'];
    }

    /**
     * @param array<string, string|null> $changes
     */
    #[Test]
    #[DataProvider('bootGaps')]
    public function it_refuses_a_base_commit_that_cannot_boot(array $changes, string $named): void
    {
        $base = $this->run->commit($changes);
        $this->run->commit(['Dockerfile.verify' => 'x', 'docker-entrypoint.sh' => 'db:ping', 'database/baseline/realtime-migrate.sh' => 'exit 0']);
        $this->run->headUp();

        $this->assertRefused($this->run->verify('baseline', '--ref', $base), 75, $named);
    }

    #[Test]
    public function it_exits_69_on_a_caller_supplied_supabase_run(): void
    {
        $this->run->commit();
        $this->run->headUp('caller-supabase');

        $this->assertRefused($this->run->verify('baseline'), 69, 'baseline is not supported in caller-supabase mode');
    }

    #[Test]
    public function without_a_ref_it_boots_the_fork_point_of_a_trunk_that_moved_on(): void
    {
        $fork = $this->run->commit();
        $this->run->git('checkout', '-q', '-b', 'feature');
        $this->run->commit(['change' => 'x']);
        $this->run->git('checkout', '-q', 'develop');
        $this->run->commit(['trunk' => 'x']);
        $this->publishTrunk();
        $this->run->git('checkout', '-q', 'feature');
        $this->run->headUp();

        $this->assertBooted($this->run->verify('baseline'), $fork);
    }

    #[Test]
    public function without_a_ref_it_refuses_a_head_on_trunk(): void
    {
        $this->run->commit();
        $this->run->commit(['change' => 'x']);
        $this->publishTrunk();
        $this->run->headUp();

        $this->assertRefused($this->run->verify('baseline'), 64, 'HEAD is on develop');
    }

    #[Test]
    public function it_reprints_a_base_run_at_the_same_sha_without_a_build(): void
    {
        $base = $this->headOnTopOfBase();
        $this->assertBooted($this->run->verify('baseline', '--ref', $base), $base);
        $this->run->clearCalls();

        $this->assertBooted($this->run->verify('baseline', '--ref', $base), $base);
        $this->assertStringNotContainsString(' build ', $this->run->calls());
    }

    #[Test]
    public function it_leaves_a_base_run_at_another_sha_up(): void
    {
        $older = $this->run->commit();
        $base = $this->headOnTopOfBase();
        $this->assertBooted($this->run->verify('baseline', '--ref', $base), $base);
        $resources = $this->run->resources("{$this->run->project}-base");

        $baseline = $this->run->verify('baseline', '--ref', $older);

        $this->assertSame(75, $baseline->getExitCode());
        $this->assertStringContainsString("already up at {$base}", $baseline->getErrorOutput());
        $this->assertSame($resources, $this->run->resources("{$this->run->project}-base"));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, array<string, string|null>}> stage, log, stub knobs, base commit changes
     */
    public static function failingStages(): iterable
    {
        yield 'archive' => ['archive', 'build/archive.log', [], ['database/baseline/versions.env' => null]];
        yield 'build' => ['build', 'build/runtime.log', ['fail' => 'build'], []];
        yield 'boot' => ['boot', 'logs/compose-up.log', ['fail' => 'up'], []];
        yield 'boot, no published port' => ['boot', 'logs/compose-up.log', ['fail' => 'port'], []];
        yield 'migrate, Auth' => ['migrate', 'logs/compose-up.log', ['fail' => 'up', 'state-auth-migrate' => "exited 1\n"], []];
        yield 'migrate, Realtime' => ['migrate', 'logs/compose-up.log', ['fail' => 'up', 'state-realtime-migrate' => "exited 1\n"], []];
        yield 'migrate, alz-core' => ['migrate', 'logs/seed/seed.log', ['fail' => 'up', 'state-seed' => "exited 1\n", 'seed-log' => "Migrations failed; nothing was seeded.\n"], []];
        yield 'migrate, thrown' => ['migrate', 'logs/seed/seed.log', ['fail' => 'up', 'state-seed' => "exited 1\n", 'seed-log' => "QueryException\n"], []];
        yield 'seed' => ['seed', 'logs/seed/seed.log', ['fail' => 'up', 'state-seed' => "exited 1\n", 'seed-log' => "Seeding failed: x\n"], []];
    }

    /**
     * @param array<string, string> $knobs
     * @param array<string, string|null> $baseChanges
     */
    #[Test]
    #[DataProvider('failingStages')]
    public function a_failed_boot_stage_exits_75_naming_it_and_its_log_and_removes_the_base_run(string $stage, string $log, array $knobs, array $baseChanges): void
    {
        $base = $this->headOnTopOfBase($baseChanges);

        foreach ($knobs as $name => $value) {
            $this->run->knob($name, $value);
        }

        $baseline = $this->run->verify('baseline', '--ref', $base);

        $this->assertSame(75, $baseline->getExitCode(), $baseline->getErrorOutput());
        $this->assertStringContainsString("the base run failed at {$stage}: {$this->run->evidence}/baseline/{$log}", $baseline->getErrorOutput());
        $this->assertFileExists("{$this->run->evidence}/baseline/{$log}");
        $this->assertSame([], $this->run->resources("{$this->run->project}-base"));
        $this->assertSame(["container/{$this->run->project}__app"], $this->run->resources($this->run->project));
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string}>
     */
    public static function unfinishedBoots(): iterable
    {
        yield 'failed' => ['fail', 'up', [], 'failed at boot'];
        yield 'expired' => ['hang', 'build', ['--time-limit', '1'], 'did not boot within the 1s limit'];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('unfinishedBoots')]
    public function after_a_boot_that_failed_or_expired_it_boots_afresh(string $knob, string $value, array $arguments, string $message): void
    {
        $base = $this->headOnTopOfBase();
        $this->run->knob($knob, $value);
        $first = $this->run->verify('baseline', '--ref', $base, ...$arguments);

        $this->assertSame(75, $first->getExitCode());
        $this->assertStringContainsString($message, $first->getErrorOutput());
        $this->assertSame([], $this->run->resources("{$this->run->project}-base"));

        $this->run->knob($knob, null);
        $this->assertBooted($this->run->verify('baseline', '--ref', $base), $base);
    }

    #[Test]
    public function down_with_an_empty_evidence_dir_removes_both_runs_and_no_other(): void
    {
        $this->headOnTopOfBase();
        $this->run->baseUp($this->run->git('rev-parse', 'HEAD^1'));

        foreach (VerifyStubRun::KINDS as $kind) {
            foreach (['', '-base', '-other'] as $suffix) {
                $this->run->addResource($kind, $this->run->project . $suffix, 'x');
            }
        }

        $empty = "{$this->run->root}/empty";
        \mkdir($empty);
        $down = $this->run->verifyOn($empty, 'down');

        $this->assertSame(0, $down->getExitCode(), $down->getOutput());
        $this->assertSame([], $this->run->resources($this->run->project));
        $this->assertSame([], $this->run->resources("{$this->run->project}-base"));
        $this->assertCount(\count(VerifyStubRun::KINDS), $this->run->resources("{$this->run->project}-other"));
    }

    #[Test]
    public function down_on_the_base_evidence_dir_never_removes_the_head_run(): void
    {
        $base = $this->headOnTopOfBase();
        $this->assertBooted($this->run->verify('baseline', '--ref', $base), $base);

        $this->assertSame(0, $this->run->verifyOn("{$this->run->evidence}/baseline", 'down')->getExitCode());
        $this->assertSame([], $this->run->resources("{$this->run->project}-base"));

        (new Process(['rm', '-rf', "{$this->run->evidence}/baseline/.verify"]))->mustRun();
        $this->assertSame(0, $this->run->verifyOn("{$this->run->evidence}/baseline", 'down')->getExitCode());
        $this->assertSame(["container/{$this->run->project}__app"], $this->run->resources($this->run->project));
    }

    /**
     * @param array<string, string|null> $baseChanges
     */
    private function headOnTopOfBase(array $baseChanges = []): string
    {
        $base = $this->run->commit($baseChanges);
        $this->run->commit(['change' => \bin2hex(\random_bytes(4))]);
        $this->run->headUp();

        return $base;
    }

    private function publishTrunk(): void
    {
        $this->run->git('clone', '-q', '--bare', $this->run->repo, "{$this->run->root}/origin.git");
        $this->run->git('remote', 'add', 'origin', "{$this->run->root}/origin.git");
    }

    private function assertRefused(Process $baseline, int $exitCode, string $message): void
    {
        $this->assertStringContainsString($message, $baseline->getErrorOutput());
        $this->assertSame($exitCode, $baseline->getExitCode());
        $this->assertSame([], $this->run->resources("{$this->run->project}-base"));
        $this->assertStringNotContainsString('compose -p', $this->run->calls());
    }

    private function assertBooted(Process $baseline, string $sha): void
    {
        $this->assertSame(0, $baseline->getExitCode(), $baseline->getErrorOutput());
        $this->assertSame([
            "baseline.sha={$sha}",
            "baseline.evidence={$this->run->evidence}/baseline",
            'baseline.entry=' . \dirname(__DIR__, 3) . '/.agents/skills/verify/verify',
            "baseline.cwd={$this->run->repo}",
        ], \explode("\n", \mb_trim($baseline->getOutput())));
        $this->assertContains("container/{$this->run->project}-base__app", $this->run->resources("{$this->run->project}-base"));
    }
}
